<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST a learned allow/deny rule to the peer site (gsc <-> jpeterson) so a
 * sender an operator marks spam/real on one is treated the same on the
 * other — spammers email every vendor. Mirrors SendLeadToHive's shape
 * (tries/backoff, no-op when unconfigured).
 *
 * One thing SendLeadToHive doesn't have to worry about: jpeterson runs
 * QUEUE_CONNECTION=sync, so ->afterCommit() there executes this job INLINE,
 * in the same request as the operator's mark-spam click. handle() therefore
 * catches its own HTTP failures rather than throwing — on gsc's real
 * (redis) queue that would otherwise be a perfectly good reason to retry,
 * but on jpeterson's sync queue a thrown exception has nowhere to go but
 * back into the admin request, and "never blocks the mark action" has to
 * hold on BOTH sides running the SAME job class.
 */
class SyncLeadFilterToPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 10;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public string $action, // 'allow' | 'deny'
        public ?string $email,
        public ?string $phone,
        public ?string $ip,
        public ?string $note,
    ) {
        // Its own queue, never the shared default: a spam block is small and
        // time-critical, and gsc's default queue routinely carries hundreds of
        // bulk SEO jobs — waiting behind them means a sender blocked here is
        // still getting through on the peer site hours later.
        $this->onQueue('lead-filters');
    }

    public function handle(): void
    {
        $peerUrl = (string) config('services.lead_filter_sync.peer_url');
        $token = (string) config('services.lead_filter_sync.token');

        // Quietly no-op if the peer isn't configured — keeps local/dev runs
        // clean, same as SendLeadToHive when HIVE_API_TOKEN is missing.
        if ($peerUrl === '' || $token === '') {
            return;
        }

        try {
            $response = Http::baseUrl($peerUrl)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->post('/api/lead-filters/sync', [
                    'action' => $this->action,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'ip' => $this->ip,
                    'note' => $this->note,
                ]);

            if (! $response->successful()) {
                Log::warning('Lead-filter sync to peer returned a non-2xx response', [
                    'action' => $this->action,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);
            }
        } catch (Throwable $e) {
            // Swallowed on purpose — see the class docblock. A best-effort
            // sync failing must never surface as a failure of the mark
            // action that triggered it.
            Log::warning('Lead-filter sync to peer failed', [
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
