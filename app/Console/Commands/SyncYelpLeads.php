<?php

namespace App\Console\Commands;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Services\YelpBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Yelp Request-a-Quote leads, read from biz.yelp.com with the server's own
 * signed-in session and stored as contact submissions — where every lead
 * starts: ss.systems lists them, and pending ones go on to hive like a web
 * form lead does.
 *
 * Yelp never hands over the customer's email or phone; the lead carries its
 * Yelp conversation link instead, which is where a reply goes. Photos the
 * customer attached are copied to the public disk at sync time, because
 * Yelp's own links expire within a day.
 */
class SyncYelpLeads extends Command
{
    protected $signature = 'yelp:sync-leads
        {--all : Re-read details and photos for every listed lead, not only new or changed ones}
        {--dry-run : Fetch and report, write nothing}';

    protected $description = 'Pull new and changed Yelp Request-a-Quote leads into contact submissions';

    public function handle(YelpBusinessService $yelp): int
    {
        if (! $yelp->isConfigured()) {
            $this->warn('Yelp business automation is not configured — nothing to sync.');

            return self::SUCCESS;
        }

        $known = ContactSubmission::withoutSiteScope()
            ->where('source', 'yelp')
            ->whereNotNull('yelp_lead_id')
            ->get(['yelp_lead_id', 'yelp_last_event_at'])
            ->mapWithKeys(fn (ContactSubmission $s) => [$s->yelp_lead_id => optional($s->yelp_last_event_at)->toIso8601ZuluString()])
            ->all();

        $outDir = storage_path('app/private/yelp-leads/incoming/' . now()->format('Ymd-His') . '-' . Str::lower(Str::random(6)));

        try {
            $result = $yelp->fetchLeads($known, $outDir, (bool) $this->option('all'), fn (string $line) => $this->line("  <fg=gray>{$line}</>"));
        } catch (\App\Exceptions\YelpSessionExpiredException $e) {
            $this->warn('Yelp session is known-dead; recovery is already in motion. Skipping this run.');

            return self::SUCCESS;
        } catch (\App\Exceptions\YelpUploadThrottledException $e) {
            $this->warn('Yelp automation is cooling down — ' . $e->getMessage());

            return self::SUCCESS;
        }

        if (! ($result['ok'] ?? false)) {
            if ($result['session_dead'] ?? false) {
                $this->warn('Yelp session is not authenticated; recovery has been triggered. Nothing synced.');
                File::deleteDirectory($outDir);

                return self::SUCCESS;
            }

            $this->error($result['error'] ?? 'Lead fetch failed.');
            File::deleteDirectory($outDir);

            return self::FAILURE;
        }

        $leads = (array) ($result['leads'] ?? []);
        $this->line(sprintf('Yelp lists %d lead(s); details read for %d.', (int) ($result['listed'] ?? count($leads)), (int) ($result['fetched'] ?? 0)));

        $created = $updated = $unchanged = 0;

        foreach ($leads as $lead) {
            if (empty($lead['encid'])) {
                continue;
            }

            if (empty($lead['detail'])) {
                $unchanged++;

                continue;
            }

            $existing = ContactSubmission::withoutSiteScope()->where('yelp_lead_id', $lead['encid'])->first();
            $attributes = $this->attributesFor($lead);

            $this->line(sprintf(
                '  %s %-22s %-28s %s',
                $existing ? 'update' : 'new   ',
                Str::limit((string) ($attributes['name'] ?? '—'), 20),
                Str::limit((string) ($lead['project']['title'] ?? ''), 26),
                $attributes['yelp_status'] ?? '',
            ));

            if ($this->option('dry-run')) {
                $existing ? $updated++ : $created++;

                continue;
            }

            if ($existing) {
                $existing->fill($attributes);
                $existing->attachments = $this->storeAttachments($existing, (array) ($lead['attachments'] ?? []), (array) $existing->attachments);
                $existing->save();
                $updated++;

                continue;
            }

            $submission = ContactSubmission::create($attributes);
            $submission->attachments = $this->storeAttachments($submission, (array) ($lead['attachments'] ?? []), []);
            // The leads admin sorts by created_at — file it when Yelp did.
            // Eloquent stores a Carbon in the zone it carries without
            // converting, so Yelp's "-05:00" wall time must be moved into
            // the app zone first or it is filed five hours off.
            $receivedAt = ! empty($lead['createdAt'])
                ? Carbon::parse($lead['createdAt'])->setTimezone(config('app.timezone'))
                : now();
            $submission->forceFill(['created_at' => $receivedAt])->save();

            // Same hand-off a web-form lead gets the moment it is stored.
            SendLeadToHive::dispatch($submission->id)->afterCommit();
            $created++;

            Log::channel('yelp')->info('Yelp leads: submission created', [
                'submission_id' => $submission->id,
                'yelp_lead_id' => $lead['encid'],
                'attachments' => count((array) $submission->attachments),
            ]);
        }

        foreach ((array) ($result['errors'] ?? []) as $error) {
            $this->warn('  ' . json_encode($error));
        }

        File::deleteDirectory($outDir);

        $this->info(sprintf('%d created, %d updated, %d unchanged.', $created, $updated, $unchanged)
            . ($this->option('dry-run') ? ' (dry run — nothing written)' : ''));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     */
    protected function attributesFor(array $lead): array
    {
        $project = (array) ($lead['project'] ?? []);
        $customer = (array) ($lead['customer'] ?? []);
        $location = (array) ($lead['location'] ?? []);

        $lines = [];
        if (! empty($project['title'])) {
            $lines[] = $project['title'];
        }
        if (! empty($project['urgency'])) {
            $lines[] = 'Timing: ' . $this->urgencyLabel((string) $project['urgency']);
        }
        $lines[] = '';
        foreach ((array) ($project['answers'] ?? []) as $qa) {
            $answers = implode(' / ', array_filter((array) ($qa['answers'] ?? [])));
            if ($answers !== '') {
                $lines[] = ($qa['question'] ?? 'Q') . "\n" . $answers;
                $lines[] = '';
            }
        }
        if (empty($project['answers']) && ! empty($project['description'])) {
            $lines[] = $project['description'];
            $lines[] = '';
        }
        if (! empty($project['keywords'])) {
            $lines[] = 'Keywords: ' . implode(', ', (array) $project['keywords']);
        }
        if (! empty($customer['location'])) {
            $lines[] = 'Customer profile location: ' . $customer['location'];
        }
        if (! empty($lead['url'])) {
            $lines[] = 'Reply on Yelp: ' . $lead['url'];
        }

        return [
            'name' => Str::limit((string) ($customer['name'] ?? 'Yelp customer'), 250, ''),
            'email' => '',
            'phone' => ! empty($lead['phone']) ? Str::limit(preg_replace('/\D+/', '', (string) $lead['phone']) ?: (string) $lead['phone'], 20, '') : null,
            'address' => null,
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'zip' => $project['zip'] ?? null,
            'message' => trim(implode("\n", $lines)),
            'source' => 'yelp',
            'referrer' => $lead['url'] ?? null,
            'status' => 'pending',
            'yelp_lead_id' => $lead['encid'],
            'yelp_conversation_id' => $lead['conversationId'] ?? null,
            'yelp_status' => $lead['workflowStatus'] ?? $lead['status'] ?? null,
            'yelp_last_event_at' => ! empty($lead['lastEventAt'])
                ? Carbon::parse($lead['lastEventAt'])->setTimezone(config('app.timezone'))
                : null,
        ];
    }

    protected function urgencyLabel(string $level): string
    {
        return match (strtoupper($level)) {
            'ASAP' => 'As soon as possible',
            'FLEXIBLE' => 'Flexible',
            default => Str::of($level)->lower()->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    /**
     * Copy the fetcher's downloads onto the public disk under the submission,
     * keeping whatever was stored on an earlier run. Yelp's URLs expire, so
     * the copy is the record.
     *
     * @param  array<int, array{encid: ?string, file: string, mime: ?string, size: ?int}>  $downloaded
     * @param  array<int, array<string, mixed>>  $existing
     * @return array<int, array<string, mixed>>
     */
    protected function storeAttachments(ContactSubmission $submission, array $downloaded, array $existing): array
    {
        $kept = collect($existing)->filter(fn ($a) => is_array($a) && ! empty($a['path']))->values();
        $have = $kept->pluck('encid')->filter()->all();

        foreach ($downloaded as $file) {
            $src = (string) ($file['file'] ?? '');
            if ($src === '' || ! is_file($src)) {
                continue;
            }
            if (! empty($file['encid']) && in_array($file['encid'], $have, true)) {
                continue;
            }

            $ext = match ((string) ($file['mime'] ?? '')) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
                default => 'jpg',
            };
            $name = Str::of((string) ($file['encid'] ?: Str::random(12)))->replaceMatches('/[^A-Za-z0-9_-]/', '_') . '.' . $ext;
            $path = "yelp-leads/{$submission->id}/{$name}";

            Storage::disk('public')->put($path, file_get_contents($src));

            $kept->push([
                'encid' => $file['encid'] ?? null,
                'path' => $path,
                'mime' => $file['mime'] ?? null,
                'size' => $file['size'] ?? filesize($src),
            ]);
        }

        return $kept->values()->all();
    }
}
