<?php

namespace App\Console\Commands;

use App\Services\GoogleBusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Lists Google reviews on the GBP listing that have no owner reply
 * (or whose reply is older than the latest review edit) and are older
 * than --max-age hours. Optionally emails the team.
 *
 * Designed to run daily so nothing slips past the 24-hour SLA Google
 * recommends for review responses.
 *
 *   php artisan gbp:unresponded-reviews                # console only
 *   php artisan gbp:unresponded-reviews --notify       # also email REVIEW_ALERT_TO
 *   php artisan gbp:unresponded-reviews --max-age=48   # widen window
 */
class GbpUnrespondedReviews extends Command
{
    protected $signature = 'gbp:unresponded-reviews
        {--max-age=24 : Only flag reviews older than this many hours without a reply}
        {--notify : Send an alert email to REVIEW_ALERT_TO}
        {--limit=20 : Max number of unresponded reviews to print}';

    protected $description = 'List Google reviews without owner replies older than the SLA window.';

    public function handle(GoogleBusinessProfileService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('Google Business Profile is not configured.');
            return self::FAILURE;
        }

        $maxAgeHours = (int) $this->option('max-age');
        $threshold = now()->subHours($maxAgeHours);

        $this->info("Checking reviews older than {$maxAgeHours}h without owner reply…");

        $reviews = $service->fetchAllReviews();
        if (empty($reviews)) {
            $err = $service->getLastError();
            if ($err) {
                $this->error('Fetch failed: ' . ($err['message'] ?? 'unknown'));
                return self::FAILURE;
            }
            $this->info('No reviews returned.');
            return self::SUCCESS;
        }

        $unresponded = [];
        foreach ($reviews as $r) {
            $createTime = isset($r['createTime']) ? Carbon::parse($r['createTime']) : null;
            $updateTime = isset($r['updateTime']) ? Carbon::parse($r['updateTime']) : $createTime;
            if (! $createTime || $updateTime->isAfter($threshold)) {
                continue;
            }

            $hasReply = ! empty($r['reviewReply']['comment'] ?? null);
            $replyTime = isset($r['reviewReply']['updateTime'])
                ? Carbon::parse($r['reviewReply']['updateTime'])
                : null;

            // Treat as unresponded if (a) no reply, OR (b) review was edited
            // after the existing reply was posted.
            $needsReply = ! $hasReply || ($replyTime && $updateTime->isAfter($replyTime));
            if (! $needsReply) {
                continue;
            }

            $unresponded[] = [
                'name'      => $r['name'] ?? '',
                'reviewer'  => $r['reviewer']['displayName'] ?? 'Anonymous',
                'stars'     => $r['starRating'] ?? '—',
                'age_hours' => (int) abs(now()->diffInHours($updateTime)),
                'comment'   => trim($r['comment'] ?? ''),
            ];
        }

        if (empty($unresponded)) {
            $this->info("✓ All reviews older than {$maxAgeHours}h have owner replies.");
            return self::SUCCESS;
        }

        // Sort oldest first.
        usort($unresponded, fn ($a, $b) => $b['age_hours'] <=> $a['age_hours']);
        $unresponded = array_slice($unresponded, 0, (int) $this->option('limit'));

        $this->warn(count($unresponded) . ' unresponded review(s):');
        foreach ($unresponded as $u) {
            $this->newLine();
            $this->line("<options=bold>{$u['reviewer']}</> · {$u['stars']} · {$u['age_hours']}h ago");
            $snippet = $u['comment'] !== ''
                ? wordwrap(mb_substr($u['comment'], 0, 220), 78, "\n  ", true)
                : '(no text)';
            $this->line("  {$snippet}");
            $this->line("  <fg=yellow>Reply via:</> php artisan tinker --execute=\"app(\\App\\Services\\GoogleBusinessProfileService::class)->replyToReview('{$u['name']}', 'YOUR REPLY')\"");
        }

        if ($this->option('notify')) {
            $this->sendAlertEmail($unresponded, $maxAgeHours);
        }

        // Exit 1 so the scheduler logs a non-zero status if SLA breached.
        return self::FAILURE;
    }

    protected function sendAlertEmail(array $unresponded, int $maxAgeHours): void
    {
        $to = config('mail.review_alert_to') ?: env('REVIEW_ALERT_TO');
        if (! $to) {
            $this->warn('REVIEW_ALERT_TO not set — skipping email.');
            return;
        }

        $count = count($unresponded);
        $lines = ["{$count} Google review(s) older than {$maxAgeHours}h need a reply.", ''];
        foreach ($unresponded as $u) {
            $lines[] = "• {$u['reviewer']} · {$u['stars']} · {$u['age_hours']}h ago";
            if ($u['comment']) {
                $lines[] = '   ' . mb_substr($u['comment'], 0, 180);
            }
        }
        $lines[] = '';
        $lines[] = 'Reply in GBP: https://business.google.com/reviews';

        $body = implode("\n", $lines);

        Mail::raw($body, function ($m) use ($to, $count) {
            $m->to($to)->subject("[GBP] {$count} unresponded review(s)");
        });

        $this->info("Alert email sent to {$to}.");
    }
}
