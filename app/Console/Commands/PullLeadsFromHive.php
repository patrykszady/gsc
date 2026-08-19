<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Services\HiveProjectsClient;
use App\Services\LeadAddressCompleter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mirror leads that hive captured into this site's admin.
 *
 * Enquiries emailed to crew@gs.construction are captured by hive2025, which
 * owns the mailbox grant and the CRM. This pulls them back so /admin/…/leads
 * shows every inbound enquiry — web form and email — in one list, instead of
 * only the ones that happened to arrive through the form.
 *
 * The direction matters: hive stays the source of truth. Rows created here
 * are a read-only mirror keyed on `hive_lead_id`, and are never pushed back
 * (SendLeadToHive only ever sends rows it created, which carry no
 * hive_lead_id until hive answers).
 */
class PullLeadsFromHive extends Command
{
    protected $signature = 'leads:pull-from-hive
        {--source=crew-email : Which hive external_source to mirror}
        {--days=30 : How far back to look}
        {--limit=100 : Max leads to request}';

    protected $description = 'Mirror leads captured by hive (e.g. crew@ inbox enquiries) into this site\'s leads admin.';

    public function handle(HiveProjectsClient $hive, LeadAddressCompleter $completer): int
    {
        $source = (string) $this->option('source');

        try {
            $leads = $hive->fetchLeads($source, now()->subDays((int) $this->option('days')), (int) $this->option('limit'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            Log::warning('leads:pull-from-hive failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach ($leads as $lead) {
            $hiveId = (int) ($lead['id'] ?? 0);
            if ($hiveId <= 0) {
                continue;
            }

            $d = (array) ($lead['lead_data'] ?? []);

            // Identity is (source, hive_lead_id) — NOT hive_lead_id alone.
            //
            // Rows this site created carry a hive_lead_id too, assigned when
            // SendLeadToHive pushed them. Matching on that id by itself makes
            // a mirrored lead collide with an unrelated web-form submission
            // that happens to share the number, and the mirror overwrites a
            // real customer's details. That is not hypothetical: it destroyed
            // two rows here before this clause existed.
            //
            // Deliberately NOT matching on email: the same person can enquire
            // twice and both are real leads.
            $existing = ContactSubmission::where('source', $source)
                ->where('hive_lead_id', $hiveId)
                ->first();

            $attributes = [
                'name' => Str::limit((string) ($d['name'] ?? 'Unknown'), 250, ''),
                'email' => Str::limit((string) ($d['email'] ?? ''), 250, ''),
                'phone' => ($d['phone'] ?? null) ? Str::limit((string) $d['phone'], 20, '') : null,
                'address' => $d['address'] ?? null,
                'city' => $d['city'] ?? null,
                // hive runs its own LeadAddressCompleter at ingest (crew-email
                // leads go through CrewLeadEmailService there), so state/zip
                // are usually already resolved — mirror them rather than
                // re-deriving. completeAddress() below is only a safety net
                // for the rare gap, and no-ops (no Geoapify call) when these
                // are already filled.
                'state' => $d['state'] ?? null,
                'zip' => $d['zip'] ?? null,
                'address_candidates' => $d['address_candidates'] ?? null,
                // Prefer the full original email over the summary.
                'message' => (string) ($d['message'] ?? $lead['notes'] ?? ''),
                'source' => $source,
                'status' => 'new',
                'hive_lead_id' => $hiveId,
                'hive_sent_at' => now(),
            ];

            $attributes = $this->completeAddress($attributes, $completer);

            // Not mass-assignable, and it matters: the leads admin sorts by
            // created_at, so mirroring with now() would file a week-old
            // enquiry at the top as if it just arrived.
            $receivedAt = ! empty($lead['date'])
                ? Carbon::parse($lead['date'])
                : now();

            if ($existing) {
                $existing->fill($attributes)->save();
                $updated++;

                continue;
            }

            $submission = ContactSubmission::create($attributes);
            $submission->forceFill(['created_at' => $receivedAt])->saveQuietly();
            $created++;
        }

        $this->info(sprintf('%s: %d fetched · %d new · %d updated', $source, count($leads), $created, $updated));

        return self::SUCCESS;
    }

    /**
     * Defensive gap-fill only: complete() is a no-op (no Geoapify call) once
     * city/state/zip are all present, which they normally already are —
     * hive completed this lead at its own ingest. Never allowed to fail the
     * whole sync: one bad address in a batch of otherwise-fine leads should
     * not lose the rest.
     */
    protected function completeAddress(array $attributes, LeadAddressCompleter $completer): array
    {
        try {
            return $completer->complete($attributes);
        } catch (\Throwable $e) {
            Log::warning('leads:pull-from-hive address completion failed', ['error' => $e->getMessage()]);

            return $attributes;
        }
    }
}
