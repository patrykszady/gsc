<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Citation;
use App\Models\Site;
use App\Services\Citations\CitationSessionService;
use App\Services\Citations\VerificationInbox;
use App\Support\Citations\ListingPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Management API for the citation builder: the directory board, the
 * canonical listing payload (so the admin can copy any field beside the
 * remote browser), and the remote session (start / poll / resume / stop).
 * The noVNC URL is never returned raw — like the platforms viewer, it is
 * cached server-side and reached through a signed redirect.
 */
class CitationsController extends Controller
{
    use BuildsApiResponses;

    protected const VIEWER_SIGNATURE_TTL_MINUTES = 30;

    public function index(CitationSessionService $sessions, VerificationInbox $inbox): JsonResponse
    {
        $this->ensureSynced();
        $status = $sessions->status();
        $rows = Citation::query()->where('site_id', Site::current()?->id)->orderBy('tier')->orderBy('name')->get();
        if ($status['slug'] ?? null) {
            $active = $rows->firstWhere('slug', $status['slug']);
            if ($active) {
                $sessions->syncCitation($active);
            }
        }

        return $this->itemResponse([
            'citations' => $rows->map(fn (Citation $c) => $this->row($c))->values()->all(),
            'counts' => $rows->countBy('status')->all(),
            'session' => $this->sessionPayload($status),
            'inbox_configured' => $inbox->isConfigured(),
            'batch' => \App\Services\Citations\CitationBatchRunner::progressState(),
            'requirements' => $sessions->checkRequirements(),
        ]);
    }

    /** Queue the automatic run over every open directory (optionally some tiers only). */
    public function batch(Request $request, \App\Services\Citations\CitationBatchRunner $runner, CitationSessionService $sessions): JsonResponse
    {
        $this->ensureSynced();
        $data = $request->validate(['tiers' => ['nullable', 'array'], 'tiers.*' => ['integer', 'between:0,3'], 'only' => ['nullable', 'array'], 'only.*' => ['string', 'max:60']]);
        if ($sessions->status()['running'] ?? false) {
            return $this->itemResponse(['ok' => false, 'error' => 'A browser session is running. Stop it first.']);
        }
        $slugs = $runner->eligible((array) ($data['tiers'] ?? []), (array) ($data['only'] ?? []))->pluck('slug')->all();
        if ($slugs === []) {
            return $this->itemResponse(['ok' => false, 'error' => 'Nothing to run: every directory is live, declined, parked for you, or waiting for verification.']);
        }
        \App\Services\Citations\CitationBatchRunner::progress($slugs, 0);
        \App\Jobs\RunCitationsBatch::dispatch($slugs);

        return $this->itemResponse(['ok' => true, 'queued' => count($slugs), 'slugs' => $slugs]);
    }

    public function payload(): JsonResponse
    {
        return $this->itemResponse(ListingPayload::make());
    }

    public function start(Request $request, string $slug, CitationSessionService $sessions): JsonResponse
    {
        $citation = $this->find($slug);
        $result = $sessions->start($citation, $request->boolean('headless'));
        if (! ($result['ok'] ?? false)) {
            return $this->itemResponse(['ok' => false, 'error' => $result['error'] ?? 'Could not start the session.']);
        }
        $citation->status = Citation::STATUS_RUNNING;
        $citation->human_reason = null;
        $citation->last_run_at = now();
        $citation->addLog('Session started from the admin', 'start');
        $citation->save();

        return $this->itemResponse(['ok' => true, 'citation' => $this->row($citation)] + $this->viewer($result));
    }

    public function poll(CitationSessionService $sessions): JsonResponse
    {
        $status = $sessions->status();
        $citation = ($status['slug'] ?? null) ? Citation::query()->where('site_id', Site::current()?->id)->where('slug', $status['slug'])->first() : null;
        if ($citation) {
            $sessions->syncCitation($citation);
        }

        return $this->itemResponse([
            'session' => $this->sessionPayload($status),
            'citation' => $citation ? $this->row($citation) : null,
            'log_tail' => $sessions->tailLog(3000),
        ]);
    }

    public function resume(string $slug, CitationSessionService $sessions): JsonResponse
    {
        $citation = $this->find($slug);
        $sessions->resume($citation);
        $citation->addLog('Continued by the admin', 'resume');
        $citation->status = Citation::STATUS_RUNNING;
        $citation->human_reason = null;
        $citation->save();

        return $this->itemResponse(['ok' => true, 'citation' => $this->row($citation)]);
    }

    public function stop(CitationSessionService $sessions): JsonResponse
    {
        $status = $sessions->status();
        $sessions->stop();
        $citation = ($status['slug'] ?? null) ? Citation::query()->where('site_id', Site::current()?->id)->where('slug', $status['slug'])->first() : null;
        if ($citation) {
            $sessions->syncCitation($citation);
            if ($citation->status === Citation::STATUS_RUNNING) {
                $citation->status = Citation::STATUS_PLANNED;
                $citation->addLog('Session stopped by the admin', 'stop');
                $citation->save();
            }
        }
        Cache::forget('platforms.remote_login_url.citations');

        return $this->itemResponse(['ok' => true, 'citation' => $citation ? $this->row($citation) : null]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $citation = $this->find($slug);
        $data = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', Citation::STATUSES)],
            'listing_url' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'account_email' => ['nullable', 'email', 'max:191'],
        ]);
        foreach (['listing_url', 'note', 'account_email'] as $f) {
            if (array_key_exists($f, $data)) {
                $citation->{$f} = $data[$f];
            }
        }
        if (! empty($data['status'])) {
            $citation->status = $data['status'];
            if ($data['status'] === Citation::STATUS_LIVE) {
                $citation->live_at = $citation->live_at ?: now();
                $citation->human_reason = null;
            }
            $citation->addLog('Status set to ' . $data['status'] . ' by the admin', 'manual');
        }
        $citation->save();

        return $this->itemResponse(['ok' => true, 'citation' => $this->row($citation)]);
    }

    public function screenshot(string $slug, string $file, CitationSessionService $sessions): BinaryFileResponse
    {
        $citation = $this->find($slug);
        abort_unless(preg_match('/^[a-z0-9._-]+\.(png|jpg)$/i', $file), 404);
        $path = $sessions->dirFor($citation) . '/shots/' . $file;
        abort_unless(is_file($path), 404);

        return response()->file($path);
    }

    protected function row(Citation $c): array
    {
        $def = $c->definition();

        return [
            'slug' => $c->slug, 'name' => $c->name, 'tier' => $c->tier, 'mechanism' => $c->mechanism,
            'homepage' => $c->homepage, 'start_url' => $c->start_url, 'listing_url' => $c->listing_url, 'status' => $c->status,
            'account_email' => $c->account_email, 'has_password' => $c->account_password !== null,
            'photos_uploaded' => $c->photos_uploaded, 'links_to_us' => $c->links_to_us, 'nofollow' => $c->nofollow,
            'human_reason' => $c->human_reason, 'note' => $c->note, 'definition_note' => $def['note'] ?? null,
            'needs' => $def['needs'] ?? [], 'photos' => (bool) ($def['photos'] ?? false),
            'log' => array_slice((array) ($c->log ?? []), -12), 'screenshots' => (array) ($c->screenshots ?? []),
            'verification' => (array) ($c->verification ?? []),
            'last_run_at' => $c->last_run_at?->toIso8601String(), 'submitted_at' => $c->submitted_at?->toIso8601String(),
            'live_at' => $c->live_at?->toIso8601String(), 'last_checked_at' => $c->last_checked_at?->toIso8601String(),
        ];
    }

    protected function sessionPayload(array $status): array
    {
        $out = ['running' => (bool) ($status['running'] ?? false), 'slug' => $status['slug'] ?? null, 'expires_at' => $status['expires_at'] ?? null, 'runner' => $status['runner'] ?? null, 'viewer_url' => null];
        if (! empty($status['viewer'])) {
            $out = $out + $this->viewer(['url' => $status['viewer'], 'expires_at' => $status['expires_at'] ?? null]);
        }

        return $out;
    }

    /** Cache the raw noVNC URL and hand back a signed redirect, exactly like the platforms viewer. */
    protected function viewer(array $result): array
    {
        if (empty($result['url'])) {
            return ['viewer_url' => null];
        }
        $expiresAt = $result['expires_at'] ?? null;
        Cache::put('platforms.remote_login_url.citations', $result['url'], now()->addSeconds($expiresAt ? max(60, (int) $expiresAt - time()) : 1800));

        return [
            'viewer_url' => URL::temporarySignedRoute('admin.platforms.viewer', now()->addMinutes(self::VIEWER_SIGNATURE_TTL_MINUTES), ['site' => Site::current()->primary_host, 'provider' => 'citations']),
            'expires_at' => $expiresAt,
        ];
    }

    protected function find(string $slug): Citation
    {
        $this->ensureSynced();

        return Citation::query()->where('site_id', Site::current()?->id)->where('slug', $slug)->firstOrFail();
    }

    protected function ensureSynced(): void
    {
        $expected = count((array) config('citations.directories', []));
        if (Citation::query()->where('site_id', Site::current()?->id)->count() < $expected) {
            \Illuminate\Support\Facades\Artisan::call('citations:sync');
        }
    }
}
