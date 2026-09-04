<?php

namespace App\Services\Citations;

use App\Models\Citation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the business mailbox for the "confirm your email" messages the
 * directories send after a signup and follows their verification links, so
 * that step needs no human. Needs the IMAP extension and
 * CITATIONS_IMAP_PASSWORD; without them every verification stays a human
 * step shown in the admin.
 */
class VerificationInbox
{
    public function isConfigured(): bool
    {
        return function_exists('imap_open') && (string) config('citations.inbox.password') !== '' && (string) config('citations.inbox.user') !== '';
    }

    /**
     * @return array{checked: int, verified: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $out = ['checked' => 0, 'verified' => [], 'errors' => []];
        if (! $this->isConfigured()) {
            $out['errors'][] = 'Mailbox not configured (CITATIONS_IMAP_PASSWORD) or the PHP imap extension is missing.';

            return $out;
        }
        $pending = Citation::query()->where('status', Citation::STATUS_PENDING_VERIFICATION)->get();
        if ($pending->isEmpty()) {
            return $out;
        }
        $cfg = (array) config('citations.inbox');
        $mailbox = sprintf('{%s:%d/imap/ssl}%s', $cfg['host'], (int) $cfg['port'], $cfg['folder']);
        $imap = @imap_open($mailbox, (string) $cfg['user'], (string) $cfg['password'], 0, 1);
        if (! $imap) {
            $out['errors'][] = 'IMAP login failed: ' . (string) imap_last_error();

            return $out;
        }
        try {
            $since = now()->subDays((int) ($cfg['lookback_days'] ?? 7))->format('d-M-Y');
            $ids = imap_search($imap, 'SINCE "' . $since . '"') ?: [];
            foreach (array_reverse($ids) as $id) {
                $header = imap_headerinfo($imap, $id);
                $from = strtolower((string) (($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '')));
                $fromDomain = self::registrable(substr($from, strpos($from, '@') + 1));
                $subject = (string) ($header->subject ?? '');
                $citation = $pending->first(fn (Citation $c) => self::registrable((string) parse_url((string) $c->homepage, PHP_URL_HOST)) === $fromDomain);
                if (! $citation) {
                    continue;
                }
                $out['checked']++;
                $body = $this->body($imap, $id);
                $links = self::extractVerificationLinks($body, $fromDomain);
                if ($links === []) {
                    continue;
                }
                foreach (array_slice($links, 0, 2) as $link) {
                    try {
                        $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/128.0'])->timeout(20)->get($link);
                        $citation->addLog(sprintf('Followed verification link from "%s": HTTP %d', mb_substr($subject, 0, 80), $resp->status()), 'verify');
                    } catch (\Throwable $e) {
                        $citation->addLog('Verification link failed: ' . mb_substr($e->getMessage(), 0, 120), 'verify');
                        continue;
                    }
                    $verification = $citation->verification ?? [];
                    $verification['email'] = 'done';
                    $verification['email_verified_at'] = now()->toDateTimeString();
                    $citation->verification = $verification;
                    $citation->status = Citation::STATUS_SUBMITTED;
                    $citation->save();
                    $out['verified'][] = $citation->slug;
                    break;
                }
            }
        } finally {
            imap_close($imap);
        }
        Log::info('citations: inbox pass', $out);

        return $out;
    }

    /**
     * Links in an email body that look like "verify / confirm / activate" on the
     * sender's own domain (or its known link-tracking subdomains).
     *
     * @return list<string>
     */
    public static function extractVerificationLinks(string $body, string $senderDomain): array
    {
        // Transfer encoding is already undone in body(); only HTML entities remain.
        preg_match_all('#https?://[^\s"\'<>)\]]+#i', $body, $m);
        $links = [];
        foreach (array_unique($m[0]) as $raw) {
            $url = html_entity_decode(rtrim($raw, '.,;'));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || self::registrable($host) !== self::registrable($senderDomain)) {
                continue;
            }
            $probe = strtolower((string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY));
            if (preg_match('/verif|confirm|activat|validate|token=|key=|code=/', $probe) && ! preg_match('/unsubscribe|preferences|privacy|terms|logo|\.(png|jpg|gif|css|js)(\?|$)/', $probe)) {
                $links[] = $url;
            }
        }

        return array_values($links);
    }

    public static function registrable(string $host): string
    {
        $parts = explode('.', strtolower(trim($host, '.')));
        $n = count($parts);
        if ($n <= 2) {
            return implode('.', $parts);
        }
        // co.uk-style suffixes are rare among US directories; keep two labels unless the TLD is a country code + generic pair.
        if (strlen($parts[$n - 1]) === 2 && in_array($parts[$n - 2], ['co', 'com', 'net', 'org'], true)) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    protected function body($imap, int $id): string
    {
        $structure = imap_fetchstructure($imap, $id);
        $text = '';
        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $i => $part) {
                if ((int) $part->type === 0) { // text
                    $chunk = (string) imap_fetchbody($imap, $id, (string) ($i + 1));
                    $text .= ((int) $part->encoding === 3 ? base64_decode($chunk) : ((int) $part->encoding === 4 ? quoted_printable_decode($chunk) : $chunk)) . "\n";
                }
            }
        }
        if ($text === '') {
            $chunk = (string) imap_body($imap, $id);
            $text = (int) ($structure->encoding ?? 0) === 3 ? base64_decode($chunk) : quoted_printable_decode($chunk);
        }

        return $text;
    }
}
