<?php

namespace App\Services\Citations;

use App\Models\Citation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the business mailbox for the "confirm your email" messages the
 * directories send after a signup and follows their verification links, so
 * that step needs no human.
 *
 * The mailbox lives on Microsoft 365, which no longer accepts password
 * IMAP, so the primary transport is Microsoft Graph with an Entra app
 * registration (application permission Mail.Read, admin-consented,
 * client-credentials flow): CITATIONS_M365_TENANT_ID / CLIENT_ID /
 * CLIENT_SECRET plus the mailbox address. Plain IMAP remains as a fallback
 * for a tenant whose mail is hosted elsewhere. With neither configured,
 * verification stays a human step shown in the admin.
 */
class VerificationInbox
{
    /** 'graph', 'imap' or null when nothing usable is configured. */
    public function mode(): ?string
    {
        $g = (array) config('citations.inbox.graph', []);
        if ((string) ($g['tenant_id'] ?? '') !== '' && (string) ($g['client_id'] ?? '') !== '' && (string) ($g['client_secret'] ?? '') !== '' && (string) config('citations.inbox.mailbox') !== '') {
            return 'graph';
        }
        if (function_exists('imap_open') && (string) config('citations.inbox.password') !== '' && (string) config('citations.inbox.user') !== '') {
            return 'imap';
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->mode() !== null;
    }

    /**
     * @return array{checked: int, verified: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $out = ['checked' => 0, 'verified' => [], 'errors' => []];
        $mode = $this->mode();
        if ($mode === null) {
            $out['errors'][] = 'Mailbox not connected: set CITATIONS_M365_TENANT_ID, CITATIONS_M365_CLIENT_ID and CITATIONS_M365_CLIENT_SECRET (Microsoft 365), or the IMAP settings.';

            return $out;
        }
        $pending = Citation::query()->where('status', Citation::STATUS_PENDING_VERIFICATION)->get();
        if ($pending->isEmpty()) {
            return $out;
        }
        $since = now()->subDays((int) config('citations.inbox.lookback_days', 7));
        try {
            $messages = $mode === 'graph' ? $this->graphMessages($since) : $this->imapMessages($since);
        } catch (\Throwable $e) {
            $out['errors'][] = mb_substr($e->getMessage(), 0, 300);

            return $out;
        }

        foreach ($messages as $message) {
            $fromDomain = self::registrable((string) $message['from_domain']);
            $citation = $pending->first(fn (Citation $c) => self::registrable((string) parse_url((string) $c->homepage, PHP_URL_HOST)) === $fromDomain);
            if (! $citation) {
                continue;
            }
            $verification = $citation->verification ?? [];
            $seen = (array) ($verification['messages_seen'] ?? []);
            if (in_array($message['id'], $seen, true)) {
                continue;
            }
            $out['checked']++;
            $links = self::extractVerificationLinks((string) $message['body'], $fromDomain);
            if ($links === []) {
                $seen[] = $message['id'];
                $verification['messages_seen'] = array_slice($seen, -50);
                $citation->verification = $verification;
                $citation->addLog(sprintf('Mail from %s ("%s") carried no verification link', $fromDomain, mb_substr((string) $message['subject'], 0, 60)), 'verify');
                $citation->save();
                continue;
            }
            foreach (array_slice($links, 0, 2) as $link) {
                try {
                    $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/128.0'])->timeout(20)->get($link);
                    $citation->addLog(sprintf('Followed verification link from "%s": HTTP %d', mb_substr((string) $message['subject'], 0, 80), $resp->status()), 'verify');
                } catch (\Throwable $e) {
                    $citation->addLog('Verification link failed: ' . mb_substr($e->getMessage(), 0, 120), 'verify');
                    continue;
                }
                $seen[] = $message['id'];
                $verification['messages_seen'] = array_slice($seen, -50);
                $verification['email'] = 'done';
                $verification['email_verified_at'] = now()->toDateTimeString();
                $citation->verification = $verification;
                $citation->status = Citation::STATUS_SUBMITTED;
                $citation->save();
                $out['verified'][] = $citation->slug;
                break;
            }
        }
        Log::info('citations: inbox pass', $out + ['mode' => $mode]);

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
        // Transfer encoding is already undone by the transport; only HTML entities remain.
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

    // ---- Microsoft Graph -------------------------------------------------

    /** Client-credentials token for Graph, cached for its lifetime. */
    protected function graphToken(): string
    {
        $g = (array) config('citations.inbox.graph');
        $key = 'citations.graph_token.' . substr(sha1((string) $g['client_id']), 0, 8);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $resp = Http::asForm()->timeout(20)->post(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode((string) $g['tenant_id'])), [
            'client_id' => (string) $g['client_id'],
            'client_secret' => (string) $g['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        if (! $resp->successful() || ! is_string($resp->json('access_token'))) {
            throw new \RuntimeException('Microsoft 365 sign-in failed: ' . ($resp->json('error_description') ?? ('HTTP ' . $resp->status())));
        }
        $token = (string) $resp->json('access_token');
        Cache::put($key, $token, now()->addSeconds(max(60, (int) $resp->json('expires_in', 3600) - 120)));

        return $token;
    }

    /**
     * Recent inbox messages of the mailbox, newest first.
     *
     * @return list<array{id: string, from_domain: string, subject: string, body: string}>
     */
    protected function graphMessages(\DateTimeInterface $since): array
    {
        $mailbox = (string) config('citations.inbox.mailbox');
        $url = sprintf('https://graph.microsoft.com/v1.0/users/%s/mailFolders/Inbox/messages', rawurlencode($mailbox));
        $resp = Http::withToken($this->graphToken())->timeout(30)->get($url, [
            '$filter' => 'receivedDateTime ge ' . $since->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime desc',
            '$top' => 50,
            '$select' => 'id,subject,from,receivedDateTime,body',
        ]);
        if (! $resp->successful()) {
            $err = $resp->json('error.message') ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException("Microsoft Graph could not read {$mailbox}: {$err}" . ($resp->status() === 403 ? ' (grant Mail.Read as an application permission and admin-consent it)' : ''));
        }
        $out = [];
        foreach ((array) $resp->json('value', []) as $m) {
            $address = strtolower((string) ($m['from']['emailAddress']['address'] ?? ''));
            $out[] = [
                'id' => (string) ($m['id'] ?? ''),
                'from_domain' => str_contains($address, '@') ? substr($address, strpos($address, '@') + 1) : '',
                'subject' => (string) ($m['subject'] ?? ''),
                'body' => (string) ($m['body']['content'] ?? ''),
            ];
        }

        return $out;
    }

    // ---- IMAP fallback -----------------------------------------------------

    /** @return list<array{id: string, from_domain: string, subject: string, body: string}> */
    protected function imapMessages(\DateTimeInterface $since): array
    {
        $cfg = (array) config('citations.inbox');
        $mailbox = sprintf('{%s:%d/imap/ssl}%s', $cfg['host'], (int) $cfg['port'], $cfg['folder']);
        $imap = @imap_open($mailbox, (string) $cfg['user'], (string) $cfg['password'], 0, 1);
        if (! $imap) {
            throw new \RuntimeException('IMAP login failed: ' . (string) imap_last_error());
        }
        try {
            $ids = imap_search($imap, 'SINCE "' . $since->format('d-M-Y') . '"') ?: [];
            $out = [];
            foreach (array_reverse($ids) as $id) {
                $header = imap_headerinfo($imap, $id);
                $out[] = [
                    'id' => 'imap:' . (string) ($header->message_id ?? $id),
                    'from_domain' => strtolower((string) ($header->from[0]->host ?? '')),
                    'subject' => (string) ($header->subject ?? ''),
                    'body' => $this->imapBody($imap, $id),
                ];
            }

            return $out;
        } finally {
            imap_close($imap);
        }
    }

    protected function imapBody($imap, int $id): string
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
