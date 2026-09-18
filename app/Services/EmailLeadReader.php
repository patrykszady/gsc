<?php

namespace App\Services;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Models\PlatformSetting;
use App\Support\SenderName;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Opcodes\MailParser\Message;

/**
 * Reads the team's inboxes (crew@, patryk@, greg@) through Nylas and turns
 * each fresh enquiry into a contact submission — the record every lead
 * starts as, whatever door it came in by, so ss.systems lists it first and
 * hive receives it the way it receives a web-form lead.
 *
 * Ported from hive's CrewLeadEmailService, which read crew@ alone and minted
 * leads straight into the CRM. That reader now only files replies; new
 * enquiries are this site's job.
 *
 * Each message is judged once, whichever run sees it: the ledger
 * (email_lead_ingests) keeps the verdict, and a message that reached two of
 * our inboxes is one enquiry because the RFC Message-ID hash on the
 * submission is checked before anything is created.
 */
class EmailLeadReader
{
    public const SOURCE = 'crew-email';

    /** Tries per classification, including the first: a 429 is a "later", not a "no". */
    public const CLASSIFY_ATTEMPTS = 3;

    public function __construct(
        private readonly LeadAddressCompleter $completer,
        private readonly HiveProjectsClient $hive,
    ) {}

    /**
     * Every mailbox this site could read, with whether it does: the ones
     * hive.contractors has connected for the business (the Platforms page
     * on ss.systems holds that connection), each switched on unless the
     * Leads page turned it off. The EMAIL_LEADS_INBOXES env pairs remain
     * the fallback for a site with no hive connection.
     *
     * @return array<int, array{mailbox: string, grant_id: string, shared: bool, enabled: bool, source: string}>
     */
    public function mailboxes(bool $live = true): array
    {
        $disabled = self::disabledMailboxes();
        $fromHive = [];

        try {
            $fromHive = $this->hive->mailboxes(cachedOnly: ! $live);
        } catch (\Throwable $e) {
            Log::channel('submissions')->warning('Email leads: could not list mailboxes from hive', ['error' => $e->getMessage()]);
        }

        if ($fromHive !== []) {
            // The shared inbox first: an enquiry addressed to it and copied
            // to someone's own is filed under the shared one.
            return collect($fromHive)
                ->sortByDesc('shared')
                ->values()
                ->map(fn (array $row) => [
                    'mailbox' => $row['email'],
                    'grant_id' => $row['grant_id'],
                    'shared' => (bool) $row['shared'],
                    'enabled' => ! in_array($row['email'], $disabled, true),
                    'source' => 'hive',
                ])
                ->all();
        }

        return collect((array) config('services.email_leads.inboxes', []))
            ->map(fn (array $row) => [
                'mailbox' => $row['mailbox'],
                'grant_id' => $row['grant_id'],
                'shared' => false,
                'enabled' => ! in_array($row['mailbox'], $disabled, true),
                'source' => 'env',
            ])
            ->values()
            ->all();
    }

    /** The mailboxes actually read this run. @return array<int, array{mailbox: string, grant_id: string}> */
    public function inboxes(): array
    {
        return collect($this->mailboxes())
            ->filter(fn (array $row) => $row['enabled'])
            ->map(fn (array $row) => ['mailbox' => $row['mailbox'], 'grant_id' => $row['grant_id']])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public static function disabledMailboxes(): array
    {
        $stored = json_decode((string) PlatformSetting::get(HiveProjectsClient::SETTING_DISABLED_MAILBOXES, '[]'), true);

        return collect(is_array($stored) ? $stored : [])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn (string $v) => mb_strtolower(trim($v)))
            ->unique()
            ->values()
            ->all();
    }

    /** @param  array<int, string>  $mailboxes */
    public static function setDisabledMailboxes(array $mailboxes): void
    {
        $clean = collect($mailboxes)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn (string $v) => mb_strtolower(trim($v)))
            ->unique()
            ->values()
            ->all();

        PlatformSetting::put(HiveProjectsClient::SETTING_DISABLED_MAILBOXES, $clean === [] ? null : json_encode($clean));
    }

    /**
     * What the Leads page shows next to each mailbox: whether it is read,
     * and what the ledger says about it — when it was last read, the
     * newest message seen, how many messages were judged and how many
     * became leads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mailboxStatus(bool $live = true): array
    {
        $ledger = EmailLeadIngest::query()
            ->selectRaw('mailbox, COUNT(*) AS messages, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS leads, MAX(message_at) AS newest_message_at, MAX(created_at) AS last_read_at', [EmailLeadIngest::STATUS_LEAD])
            ->groupBy('mailbox')
            ->get()
            ->keyBy('mailbox');

        return collect($this->mailboxes($live))
            ->map(function (array $row) use ($ledger) {
                $stats = $ledger->get($row['mailbox']);

                return $row + [
                    'messages' => (int) ($stats->messages ?? 0),
                    'leads' => (int) ($stats->leads ?? 0),
                    'newest_message_at' => $stats?->newest_message_at ? Carbon::parse($stats->newest_message_at)->toIso8601String() : null,
                    'last_read_at' => $stats?->last_read_at ? Carbon::parse($stats->last_read_at)->toIso8601String() : null,
                ];
            })
            ->all();
    }

    /**
     * @return array{inboxes:int, fetched:int, leads:int, skipped:int, failed:int, details:array<int, array<string, mixed>>}
     */
    public function ingest(bool $dryRun = false, ?int $limit = null, ?\DateTimeInterface $since = null): array
    {
        $out = ['inboxes' => 0, 'fetched' => 0, 'leads' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        // Messages the model could not be asked about last time get another
        // go first — they are older than the watermark, so the read below
        // would never see them again.
        if (! $dryRun) {
            $retries = EmailLeadIngest::where('status', EmailLeadIngest::STATUS_FAILED)
                ->where('skip_reason', 'classifier')
                ->where('created_at', '>=', now()->subDays(3))
                ->latest('id')
                ->limit(20)
                ->get();

            foreach ($retries as $row) {
                $result = $this->reprocessLedgerRow($row);
                $result['retried'] = true;
                $out['details'][] = $result;
                $out[match ($result['status']) {
                    EmailLeadIngest::STATUS_LEAD => 'leads',
                    EmailLeadIngest::STATUS_FAILED => 'failed',
                    default => 'skipped',
                }]++;
            }
        }

        // Config order is precedence: crew@ first, so an enquiry addressed to
        // the shared mailbox and copied to someone's own is filed under crew@.
        foreach ($this->inboxes() as $inbox) {
            $mailbox = $inbox['mailbox'];
            $grantId = $inbox['grant_id'];

            $messages = $this->fetch(
                $grantId,
                $mailbox,
                $limit ?? (int) config('services.email_leads.poll_limit', 25),
                $since ?? $this->since($mailbox),
            );

            if ($messages === null) {
                Log::channel('submissions')->error('Email leads: inbox could not be read', [
                    'mailbox' => $mailbox,
                    'grant_id' => $grantId,
                ]);

                continue;
            }

            $out['inboxes']++;
            $out['fetched'] += count($messages);

            foreach ($messages as $message) {
                $result = $this->ingestMessage((array) $message, $mailbox, $grantId, $dryRun);
                $result['mailbox'] = $mailbox;
                $out['details'][] = $result;
                $out[match ($result['status']) {
                    EmailLeadIngest::STATUS_LEAD => 'leads',
                    EmailLeadIngest::STATUS_FAILED => 'failed',
                    default => 'skipped',
                }]++;
            }

        }

        return $out;
    }

    /**
     * Re-run one message the ledger already holds — after a triage rule
     * changed, say — by fetching it again and judging it as if for the first
     * time. The old row goes first, or the dedupe would answer "already
     * ingested" before anything else ran.
     *
     * @return array<string, mixed> the ingestMessage() summary
     */
    public function reprocessLedgerRow(EmailLeadIngest $row): array
    {
        $grantId = (string) $row->grant_id;
        $mailbox = (string) $row->mailbox;
        $nylasId = (string) $row->nylas_message_id;

        $response = $this->http(60)->get($this->url("/v3/grants/{$grantId}/messages/{$nylasId}"), array_filter([
            'shared_from' => $this->sharedFrom($grantId, $mailbox),
            'fields' => 'include_headers',
        ]));

        if (! $response->successful() || ! is_array($response->json('data'))) {
            return ['status' => EmailLeadIngest::STATUS_FAILED, 'reason' => 'fetch', 'http' => $response->status(), 'mailbox' => $mailbox];
        }

        $row->delete();

        return $this->ingestMessage((array) $response->json('data'), $mailbox, $grantId, false) + ['mailbox' => $mailbox];
    }

    /**
     * The inbox's messages since the watermark, newest first, with headers.
     *
     * `shared_from` is what makes crew@ readable at all: it has no grant of
     * its own, so Nylas proxies the read through a user grant that has access
     * to the shared mailbox. A grant reading its own inbox needs no such
     * thing — sending it anyway is an error.
     *
     * @return array<int, array<string, mixed>>|null null = could not read
     */
    protected function fetch(string $grantId, string $mailbox, int $limit, \DateTimeInterface $since): ?array
    {
        $sharedFrom = $this->sharedFrom($grantId, $mailbox);
        if ($sharedFrom === false) {
            return null;
        }

        $folder = $this->inboxFolderId($grantId, $mailbox, $sharedFrom);
        if ($folder === null) {
            // Without the folder the API would answer from every folder,
            // Sent included; better to read nothing this run.
            Log::channel('submissions')->warning('Email leads: no Inbox folder found', ['mailbox' => $mailbox, 'grant_id' => $grantId]);

            return null;
        }

        $response = $this->http(60)->get($this->url("/v3/grants/{$grantId}/messages"), array_filter([
            'shared_from' => $sharedFrom,
            'in' => $folder,
            'limit' => $limit,
            'received_after' => $since->getTimestamp(),
            // Headers carry the bulk-mail markers (List-Unsubscribe,
            // Precedence, Auto-Submitted) that let triage reject marketing
            // for free, and the RFC Message-ID that is the dedupe identity.
            'fields' => 'include_headers',
        ], fn ($v) => $v !== null));

        if (! $response->successful()) {
            Log::channel('submissions')->warning('Email leads: grant could not read mailbox', [
                'mailbox' => $mailbox,
                'grant_id' => $grantId,
                'status' => $response->status(),
                'body' => Str::limit((string) $response->body(), 300),
            ]);

            return null;
        }

        return (array) ($response->json('data') ?? []);
    }

    /**
     * The `shared_from` value for reading $mailbox through $grantId: the
     * mailbox when it is not the grant's own, null when it is, false when the
     * grant's identity could not be established (then nothing is read — a
     * wrong guess either errors or reads the wrong inbox).
     */
    protected function sharedFrom(string $grantId, string $mailbox): string|null|false
    {
        $own = $this->grantEmail($grantId);
        if ($own === null) {
            return false;
        }

        return strcasecmp($own, $mailbox) === 0 ? null : $mailbox;
    }

    /** The mailbox a grant belongs to, cached a day. */
    protected function grantEmail(string $grantId): ?string
    {
        return cache()->remember("email_leads:grant-email:{$grantId}", now()->addDay(), function () use ($grantId): ?string {
            $response = $this->http(15)->get($this->url("/v3/grants/{$grantId}"));
            $email = mb_strtolower(trim((string) ($response->json('data.email') ?? '')));

            return $response->successful() && $email !== '' ? $email : null;
        });
    }

    /** The Inbox folder id for a mailbox read through a grant, cached a day. */
    protected function inboxFolderId(string $grantId, string $mailbox, ?string $sharedFrom): ?string
    {
        return cache()->remember("email_leads:inbox:{$grantId}:{$mailbox}", now()->addDay(), function () use ($grantId, $sharedFrom): ?string {
            $response = $this->http(45)->get($this->url("/v3/grants/{$grantId}/folders"), array_filter(['shared_from' => $sharedFrom]));

            if (! $response->successful()) {
                return null;
            }

            // A shared read mixes the shared mailbox's folders with the grant
            // owner's own; the shared ones come first, so the FIRST Inbox is
            // the right one either way.
            foreach ((array) $response->json('data') as $folder) {
                $attributes = array_map('strtolower', (array) ($folder['attributes'] ?? []));

                if (strcasecmp((string) ($folder['name'] ?? ''), 'Inbox') === 0 || in_array('\\inbox', $attributes, true)) {
                    return $folder['id'] ?? null;
                }
            }

            return null;
        });
    }

    /** @return array<string, mixed> */
    protected function ingestMessage(array $message, string $mailbox, string $grantId, bool $dryRun): array
    {
        $nylasId = (string) ($message['id'] ?? '');
        $from = (array) ($message['from'][0] ?? []);
        $fromEmail = mb_strtolower(trim((string) ($from['email'] ?? '')));
        $subject = (string) ($message['subject'] ?? '');
        $body = $this->plainBody($message);
        $headers = $this->headerMap($message);
        $rfcId = trim((string) ($headers['message-id'] ?? ''));
        $identity = $this->identity($rfcId, $nylasId);

        $base = [
            'mailbox' => $mailbox,
            'grant_id' => $grantId,
            'nylas_message_id' => $nylasId,
            'rfc_message_id' => $rfcId !== '' ? Str::limit($rfcId, 255, '') : null,
            'from_email' => $fromEmail !== '' ? Str::limit($fromEmail, 255, '') : null,
            'from_name' => isset($from['name']) && trim((string) $from['name']) !== '' ? Str::limit(trim((string) $from['name']), 255, '') : null,
            'subject' => Str::limit($subject, 500, ''),
            'message_at' => isset($message['date']) ? now()->setTimestamp((int) $message['date']) : null,
        ];

        $summary = ['subject' => Str::limit($subject, 60, ''), 'from' => $fromEmail];

        if ($nylasId === '') {
            return $summary + ['status' => EmailLeadIngest::STATUS_SKIPPED, 'reason' => 'no_id'];
        }

        // Judged on an earlier run.
        if (! $dryRun && EmailLeadIngest::where('nylas_message_id', $nylasId)->exists()) {
            return $summary + ['status' => EmailLeadIngest::STATUS_SKIPPED, 'reason' => 'already_ingested'];
        }

        // The same enquiry is already on file: it reached two of our inboxes,
        // or hive read it before this site did and pushed it here.
        $twin = ContactSubmission::withoutSiteScope()->where('email_message_id', $identity)->first();
        if ($twin) {
            return $this->skip($base, 'duplicate', $dryRun, $summary, ['submission_id' => $twin->id]);
        }

        if ($reason = $this->triage($fromEmail, $subject, $body, $message, $headers, $grantId)) {
            return $this->skip($base, $reason, $dryRun, $summary);
        }

        $verdict = $this->classify($subject, $body, $fromEmail);

        // No verdict is not a verdict: a message the model could not be asked
        // about (outage, bad answer, no key) is held as failed and judged again
        // on a later run, never filed on the strength of nothing (2026-09-16:
        // an Amazon return confirmation became a lead this way).
        if ($verdict['extraction_status'] !== 'ok') {
            if (! $dryRun) {
                EmailLeadIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                    'status' => EmailLeadIngest::STATUS_FAILED,
                    'skip_reason' => 'classifier',
                    'is_lead' => null,
                    'confidence' => null,
                    'submission_id' => null,
                    'error' => 'classifier '.$verdict['extraction_status'],
                ]);
            }

            return $summary + ['status' => EmailLeadIngest::STATUS_FAILED, 'reason' => 'classifier'];
        }

        // A "not a lead" the model is at least half sure of discards; only a
        // genuinely torn answer still files, so a real enquiry is not lost to
        // a hedge.
        if ($verdict['is_lead'] === false && $verdict['confidence'] >= 0.5) {
            return $this->skip($base, 'not_a_lead', $dryRun, $summary, ['confidence' => $verdict['confidence']]);
        }

        if ($dryRun) {
            return $summary + ['status' => EmailLeadIngest::STATUS_LEAD, 'confidence' => $verdict['confidence'], 'reason' => $verdict['reason']];
        }

        try {
            $submission = $this->createSubmission($message, $base, $body, $verdict, $identity);

            EmailLeadIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                'status' => EmailLeadIngest::STATUS_LEAD,
                'skip_reason' => null,
                'is_lead' => true,
                'confidence' => $verdict['confidence'],
                'submission_id' => $submission->id,
                'error' => null,
            ]);

            SendLeadToHive::dispatch($submission->id)->afterCommit();

            Log::channel('submissions')->info('Email lead captured', [
                'submission_id' => $submission->id,
                'mailbox' => $mailbox,
                'from' => $fromEmail,
            ]);

            return $summary + ['status' => EmailLeadIngest::STATUS_LEAD, 'submission_id' => $submission->id, 'confidence' => $verdict['confidence']];
        } catch (\Throwable $e) {
            EmailLeadIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                'status' => EmailLeadIngest::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 2000, ''),
            ]);

            Log::channel('submissions')->error('Email leads: failed to create submission', [
                'nylas_message_id' => $nylasId,
                'mailbox' => $mailbox,
                'error' => $e->getMessage(),
            ]);

            return $summary + ['status' => EmailLeadIngest::STATUS_FAILED, 'reason' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    protected function skip(array $base, string $reason, bool $dryRun, array $summary, array $extra = []): array
    {
        if (! $dryRun) {
            EmailLeadIngest::updateOrCreate(['nylas_message_id' => $base['nylas_message_id']], $base + [
                'status' => EmailLeadIngest::STATUS_SKIPPED,
                'skip_reason' => $reason,
                'is_lead' => false,
                'confidence' => $extra['confidence'] ?? null,
                'submission_id' => $extra['submission_id'] ?? null,
            ]);
        }

        return $summary + ['status' => EmailLeadIngest::STATUS_SKIPPED, 'reason' => $reason] + $extra;
    }

    /**
     * Cheap, certain exclusions. Returns a skip reason or null to continue.
     *
     * Everything here is something no model should be asked to judge and no
     * model should be paid to judge.
     *
     * @param  array<string, string>  $headers  lowercased header name => value
     */
    protected function triage(string $fromEmail, string $subject, string $body, array $message, array $headers, ?string $grantId = null): ?string
    {
        if ($fromEmail === '') {
            return 'no_sender';
        }

        // Our own outbound mail lands in these inboxes too. Without this,
        // every estimate and follow-up becomes a fake lead.
        if ($this->isInternal($fromEmail)) {
            return 'internal';
        }

        // Machine senders, by local part or by domain. The first live read
        // (2026-09-15) paid the model to reject Apple's no_reply@, Amazon's
        // order-update@ / shipment-tracking@ / auto-confirm@; the next day
        // return@amazon.com got through and became a lead while the model
        // was down. Nobody enquires about a remodel from a retailer's or a
        // bank's domain.
        if ($this->isMachineSender($fromEmail)) {
            return 'automated';
        }

        if (isset($headers['list-unsubscribe'])
            || (isset($headers['auto-submitted']) && strtolower($headers['auto-submitted']) !== 'no')
            || (isset($headers['precedence']) && preg_match('/bulk|list|auto_reply/i', $headers['precedence']))
            || isset($headers['x-auto-response-suppress'])) {
            return 'automated';
        }

        // A reply continues a conversation we are already having — someone
        // answering an estimate or a consultation email. That lead exists;
        // hive files the reply on it. A FORWARD is not a reply: a homeowner
        // who prepared one bid request and forwards it to every contractor
        // she found is exactly the enquiry this reader exists to catch, and
        // her subject says "Fwd:" while her headers reference the original
        // in her own mailbox. So a forward goes on to the classifier.
        $isForward = (bool) preg_match('/^\s*(fw|fwd|tr|wg|pd|i)\s*(\[\d+\])?\s*:/i', $subject);

        if (! $isForward && (isset($headers['in-reply-to']) || isset($headers['references']))) {
            return 'reply';
        }

        if (! $isForward && preg_match('/^\s*(re|aw|sv|vs|odp)\s*(\[\d+\])?\s*:/i', $subject)) {
            return 'reply';
        }

        if (trim($subject) === '' && trim($body) === '') {
            return 'empty';
        }

        // Someone already on file is never a NEW lead, whatever the subject
        // line says — a client mid-project writing "window order" with three
        // questions is answering us, not enquiring. Writing from a second
        // address with the one we know in CC is that same someone.
        if ($this->knownSender($fromEmail, $message)) {
            return 'reply';
        }

        // A supplier answering something WE asked for — "Hi Patryk, thanks
        // for your interest… your revised quote for the Brodson job is
        // $7,949.85" — greets one of us by name and talks quotes, orders,
        // lead times. It carries no In-Reply-To (their quoting system sent
        // a fresh message), so the reply check above never sees it (2026-09-16,
        // EzeBreezeWindows.com). We are the customer; it is not a lead and
        // not spam either — just not ours to file.
        if ($this->isSupplierMail($subject, $body, $message, $grantId)) {
            return 'supplier';
        }

        return null;
    }

    /**
     * Mail addressed to one of our own people by first name that reads like
     * a vendor's side of a purchase.
     */
    protected function isSupplierMail(string $subject, string $body, array $message, ?string $grantId = null): bool
    {
        $names = $this->teamNames($message, $grantId);
        if ($names === []) {
            return false;
        }

        // The greeting opens a line near the top; a logo's alt text or the
        // addressee's own name may come before it ("EzeBreezeWindows.com /
        // Patryk Szady / Hi Patryk,").
        $opening = mb_substr(ltrim($body), 0, 600);
        if (preg_match_all('/^[ \t]*(?:hi|hello|hey|dear|good[ \t]+(?:morning|afternoon|evening))[ \t]+(?:mr\.?[ \t]+|ms\.?[ \t]+|mrs\.?[ \t]+)?([\p{L}\'’\-]+)/imu', $opening, $m) === 0) {
            return false;
        }

        $greeted = collect($m[1])->map(fn (string $name) => strtolower(Str::ascii($name)))->intersect($names);
        if ($greeted->isEmpty()) {
            return false;
        }

        $text = $subject."\n".$body;

        // Their side of a sale, not ours: "a quote for our kitchen" is what a
        // homeowner asks for, so "quote for" alone is not on this list.
        return preg_match('/\b(?:your\s+(?:revised\s+|updated\s+|new\s+)?quote|revised\s+quote|quote\s+(?:#|no\.?|number)\s*\d|quoted\s+price|ready\s+to\s+order|your\s+order\b|order\s+(?:confirmation|number|#)|purchase\s+order|invoice\b|price\s+list|thanks?\s+(?:you\s+)?for\s+your\s+interest|lead\s+times?\b|shipping\s+update|tracking\s+(?:number|info)|attached\s+(?:is|are)\s+(?:your|the)\s+(?:quote|estimate|proposal))\b/iu', $text) === 1;
    }

    /**
     * Our people by first name: the mailbox names that are a person's
     * (patryk@, greg@ — not crew@ or info@), the person whose grant this
     * message was read through (already looked up for the read, so no
     * extra call), whoever at our domains it was addressed to, plus
     * whatever is configured.
     *
     * @return array<int, string>
     */
    protected function teamNames(array $message = [], ?string $grantId = null): array
    {
        $generic = ['crew', 'info', 'office', 'team', 'sales', 'hello', 'contact', 'admin', 'support', 'mail', 'jobs', 'estimates', 'service', 'billing', 'accounting'];

        $recipients = collect(array_merge((array) ($message['to'] ?? []), (array) ($message['cc'] ?? [])))
            ->pluck('email')
            ->filter(fn ($email) => is_string($email) && $this->isInternal(mb_strtolower(trim($email))));

        return collect((array) config('services.email_leads.team_names', []))
            ->merge(collect($this->mailboxes(live: false))->pluck('mailbox'))
            ->merge($grantId !== null ? [$this->grantEmail($grantId)] : [])
            ->merge($recipients)
            ->map(fn ($name) => Str::before(strtolower(trim(Str::ascii((string) $name))), '@'))
            ->filter(fn (string $name) => preg_match('/^[a-z]{2,}$/', $name) === 1 && ! in_array($name, $generic, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An address no person writes an enquiry from: a role or robot local
     * part, or a retailer, carrier, bank or platform domain.
     */
    public function isMachineSender(string $fromEmail): bool
    {
        $fromEmail = mb_strtolower(trim($fromEmail));

        if (preg_match('/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|donotreply|mailer-daemon|postmaster|bounces?|auto-?confirm(?:ation)?s?|order-?updates?|orders?|shipment-?tracking|shipping|deliver(?:y|ies)|returns?|refunds?|receipts?|billing|invoices?|payments?|accounts?|customer[-_.]?(?:service|care|support)|support|help(?:desk)?|service|updates?|confirm(?:ation)?s?|verif(?:y|ication)|security|reminders?|digest|feedback|survey|promo(?:tions?)?|offers?|deals?|news|store|shop|system|robot|bot|daemon|notifications?|alerts?|newsletter|marketing)([-_.+@]|$)/i', $fromEmail)) {
            return true;
        }

        $domain = Str::after($fromEmail, '@');
        if ($domain === '' || $domain === $fromEmail) {
            return false;
        }

        foreach ((array) config('services.email_leads.machine_domains', []) as $machine) {
            $machine = mb_strtolower(trim((string) $machine));
            if ($machine !== '' && ($domain === $machine || str_ends_with($domain, '.'.$machine))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cheap rules that can be re-applied to a submission already on
     * file from what was kept of it — no headers, so not the bulk-mail
     * markers. A skip reason, or null when nothing here refuses it.
     */
    public function retriageStored(string $fromEmail, string $subject, string $body, string $mailbox, ?string $grantId = null): ?string
    {
        if ($this->isInternal(mb_strtolower(trim($fromEmail)))) {
            return 'internal';
        }

        if ($this->isMachineSender($fromEmail)) {
            return 'automated';
        }

        if ($this->isSupplierMail($subject, $body, ['to' => [['email' => $mailbox]]], $grantId)) {
            return 'supplier';
        }

        return null;
    }

    protected function isInternal(string $email): bool
    {
        $domain = Str::after($email, '@');

        foreach ((array) config('services.email_leads.internal_domains', []) as $internal) {
            if ($domain === $internal || str_ends_with($domain, '.'.$internal)) {
                return true;
            }
        }

        return false;
    }

    /** Any address on the message — sender, To, CC — that a submission already carries. */
    protected function knownSender(string $fromEmail, array $message): bool
    {
        $addresses = collect([$fromEmail])
            ->merge(array_column((array) ($message['to'] ?? []), 'email'))
            ->merge(array_column((array) ($message['cc'] ?? []), 'email'))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->reject(fn (string $email) => $this->isInternal($email))
            ->unique()
            ->values()
            ->all();

        if ($addresses === []) {
            return false;
        }

        return ContactSubmission::withoutSiteScope()
            ->whereIn(DB::raw('LOWER(email)'), $addresses)
            ->exists();
    }

    /**
     * Post one classification, retrying a rate limit or a server error.
     *
     * A run reads several inboxes back to back, so a batch can walk straight
     * into the provider's per-minute limit: on 2026-09-18 five messages in one
     * run came back 429 and were filed unclassified, a real enquiry among them.
     * A 429 is a "later", not a "no" — wait out the provider's own Retry-After
     * when it sends one, otherwise back off, and give up after three tries so a
     * scheduled run can never hang on a sulking API. Only 429: a 5xx is already
     * held and re-read on the next run.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function classificationRequest(string $apiKey, array $payload): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            // A rate limit only. A 5xx already has an answer in this design:
            // the message is held as failed and read again next run, which
            // test_a_message_the_model_could_not_judge_is_held_and_judged_again_next_run
            // pins. A 429 is different — the next run arrives in the same
            // burst and is refused the same way, so the lead waits hours.
            if ($response->status() !== 429 || $attempt >= self::CLASSIFY_ATTEMPTS) {
                return $response;
            }

            $after = (int) $response->header('Retry-After');
            $wait = $after > 0 ? min($after, 20) : $attempt * 3;

            Log::channel('submissions')->info('Email leads: classifier asked us to wait', [
                'status' => $response->status(),
                'attempt' => $attempt,
                'sleeping' => $wait,
            ]);

            Sleep::for($wait)->seconds();
        }
    }

    /**
     * Ask the model whether this is a prospect enquiry, and pull out the
     * details worth having on the lead. One call does both.
     *
     * @return array{is_lead:?bool, confidence:float, reason:?string, extraction_status:string, fields:array<string,mixed>}
     */
    public function classify(string $subject, string $body, string $fromEmail): array
    {
        $fallback = ['is_lead' => null, 'confidence' => 0.0, 'reason' => null, 'extraction_status' => 'skipped', 'fields' => []];

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return $fallback;
        }

        $system = <<<'TXT'
You triage the inboxes of GS Construction, a residential remodeling
general contractor in the Chicago suburbs.

Decide whether a message is a PROSPECT ENQUIRY: someone outside the company
asking about work they want done, or responding to an estimate they requested.

The direction of the offer is what decides it. A lead is someone who wants to
BUY construction work from GS. Anyone SELLING something to GS is not a lead,
however friendly the wording — subcontractors and suppliers touting their
services, partnership or "collaboration" proposals, marketing and SEO
agencies, recruiters, software vendors. This holds in any language: a Polish
"oferta współpracy" or "współpraca" is a cooperation offer, i.e. a
solicitation, not an enquiry.

Also NOT enquiries: mail the company itself sent; anything where GS is the
CUSTOMER — a supplier's quote or revised quote, a price, an order
confirmation, a shipping or lead-time update, or a "thanks for your
interest" reply to something GS asked for, especially when it greets one of
GS's own people by first name; order, return, refund and shipping
confirmations, receipts and account notices from retailers, carriers, banks
and online services; invoices and payment notices; newsletters and
promotions; legal or demand letters; automated notifications; and platform
emails that merely announce a lead exists elsewhere.

When a message IS an enquiry, extract what it actually states. Never invent a
value — use null for anything not present. Quote the address exactly as
written. Keep scope_summary to one or two sentences in plain language.

Enquiries often come from a couple: put EVERY name in `name` as written
("Amy Dusto and Chris Ecker") and EVERY number in `phone` separated by " / ",
in the same order as the names. Give `zip` only when the message states it —
a guessed ZIP is worse than none.

`confidence` is how sure you are of your `is_lead` answer, from 0 to 1 —
NOT how likely the message is to be a lead. A newsletter you are certain is
not an enquiry is is_lead=false with confidence 0.95.
TXT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_lead', 'confidence', 'reason', 'name', 'phone', 'address', 'city', 'zip', 'project_type', 'scope_summary', 'timeline', 'budget'],
            'properties' => [
                'is_lead' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
                'name' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
                'address' => ['type' => ['string', 'null']],
                'city' => ['type' => ['string', 'null']],
                'zip' => ['type' => ['string', 'null']],
                'project_type' => ['type' => ['string', 'null']],
                'scope_summary' => ['type' => ['string', 'null']],
                'timeline' => ['type' => ['string', 'null']],
                'budget' => ['type' => ['string', 'null']],
            ],
        ];

        try {
            $response = $this->classificationRequest($apiKey, [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                // The same email must get the same verdict on every
                // read: a dry run said "not a lead", the scheduled run
                // minutes later filed it (2026-09-15, Apple's terms
                // notice), because sampling at the default temperature
                // differed.
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "From: {$fromEmail}\nSubject: {$subject}\n\n".Str::limit($body, 6000, '')],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'email_lead_triage', 'strict' => true, 'schema' => $schema],
                ],
            ]);

            if (! $response->successful()) {
                Log::channel('submissions')->warning('Email leads: classification request failed', [
                    'status' => $response->status(),
                    'from' => $fromEmail,
                    'subject' => Str::limit($subject, 80),
                ]);

                return array_merge($fallback, ['extraction_status' => 'failed']);
            }

            $data = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);
            if (! is_array($data)) {
                return array_merge($fallback, ['extraction_status' => 'failed']);
            }

            // The schema says string-or-null and the model occasionally
            // satisfies it with the STRING "null". Placeholders become nulls
            // before anything lands in a form field.
            $data = array_map(function ($value) {
                if (is_string($value) && in_array(mb_strtolower(trim($value)), ['null', 'none', 'n/a', 'unknown', ''], true)) {
                    return null;
                }

                return $value;
            }, $data);

            return [
                'is_lead' => (bool) ($data['is_lead'] ?? false),
                'confidence' => (float) ($data['confidence'] ?? 0),
                'reason' => $data['reason'] ?? null,
                'extraction_status' => 'ok',
                'fields' => $data,
            ];
        } catch (\Throwable $e) {
            Log::channel('submissions')->warning('Email leads: classification threw', ['error' => $e->getMessage()]);

            return array_merge($fallback, ['extraction_status' => 'failed']);
        }
    }

    /**
     * Build the submission from the email itself, then let extraction
     * improve it: who wrote and what they wrote come from the message, not
     * the model.
     */
    protected function createSubmission(array $message, array $base, string $body, array $verdict, string $identity): ContactSubmission
    {
        $fields = (array) $verdict['fields'];

        // The sign-off gives a first name ("Will"); the From header's display
        // name carries the surname ("William Johnson89 wa"). Paired, the lead
        // gets both. The raw header is the fallback, the address the last resort.
        $name = SenderName::complete($fields['name'] ?? null, $base['from_name'], $base['from_email'])
            ?? $base['from_name']
            ?? Str::before((string) $base['from_email'], '@');

        $data = [
            'name' => Str::limit($name, 250, ''),
            'email' => (string) $base['from_email'],
            'phone' => $this->phone($fields['phone'] ?? null),
            'address' => $this->text($fields['address'] ?? null, 500),
            'city' => $this->text($fields['city'] ?? null, 120),
            'state' => null,
            'zip' => $this->text($fields['zip'] ?? null, 10),
            'message' => Str::limit($body, 20000, ''),
            'subject' => $this->text($base['subject'], 255),
            'source' => self::SOURCE,
            'status' => 'pending',
            'email_message_id' => $identity,
        ];

        $data = $this->completer->complete($data);

        $extracted = array_filter([
            'mailbox' => $base['mailbox'],
            'project_type' => $this->text($fields['project_type'] ?? null, 255),
            'scope_summary' => $this->text($fields['scope_summary'] ?? null, 1000),
            'timeline' => $this->text($fields['timeline'] ?? null, 255),
            'budget' => $this->text($fields['budget'] ?? null, 255),
            // Couples write in together and CC each other — the other
            // people on the enquiry.
            'cc_emails' => $this->partnerEmails($message, (string) $base['from_email']),
            'address_candidates' => $data['address_candidates'] ?? null,
            'is_lead' => $verdict['is_lead'],
            'confidence' => $verdict['confidence'],
            'reason' => $verdict['reason'],
            'extraction_status' => $verdict['extraction_status'],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        unset($data['address_candidates']);

        $submission = ContactSubmission::create($data + ['extracted' => $extracted]);
        // Filed when it arrived, not when it was read — the admin sorts by created_at.
        $submission->forceFill(['created_at' => $base['message_at'] ?? now()])->saveQuietly();

        // Whatever the enquirer attached — a bid request form, drawings,
        // photos of the damage — is often the substance of the enquiry.
        // Failure is non-fatal: the lead exists either way.
        try {
            $files = $this->storeAttachments($message, $base, $submission);
            if ($files !== []) {
                $submission->forceFill(['attachments' => $files])->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::channel('submissions')->warning('Email leads: attachment capture failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $submission;
    }

    /** The first number the classifier reported, digits only, sized for the column. */
    protected function phone(mixed $value): ?string
    {
        $first = trim((string) Str::before((string) $value, '/'));
        $digits = preg_replace('/\D+/', '', $first) ?? '';

        return $digits !== '' ? Str::limit($digits, 20, '') : null;
    }

    protected function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $max, '') : null;
    }

    /**
     * Addresses on the enquiry that belong to the enquirers — everyone on it
     * except the sender and our own mailboxes.
     *
     * @return array<int, string>
     */
    protected function partnerEmails(array $message, string $fromEmail): array
    {
        return collect(array_column((array) ($message['cc'] ?? []), 'email'))
            ->merge(array_column((array) ($message['to'] ?? []), 'email'))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->reject(fn (string $email) => $email === $fromEmail || $this->isInternal($email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Download the enquiry's real attachments onto the public disk.
     *
     * Images, PDFs and Word documents — bid-request forms arrive as .docx and
     * carry the contact details the body leaves out. Inline parts (logos,
     * tracking pixels) are already out by the is_inline flag.
     *
     * @return array<int, array{path:string, name:string, mime:string, size:int}>
     */
    protected function storeAttachments(array $message, array $base, ContactSubmission $submission): array
    {
        $attachments = array_values(array_filter(
            (array) ($message['attachments'] ?? []),
            fn ($a) => is_array($a)
                && ($a['is_inline'] ?? false) === false
                && preg_match(
                    '#^(image/|application/pdf|application/msword|application/vnd\.openxmlformats-officedocument\.wordprocessingml)#i',
                    (string) ($a['content_type'] ?? '')
                )
                && (int) ($a['size'] ?? 0) <= 25 * 1024 * 1024,
        ));

        $sharedFrom = $this->sharedFrom((string) $base['grant_id'], (string) $base['mailbox']);
        $stored = [];
        // Filename → bytes from the message's raw MIME, fetched once, for a
        // shared mailbox or when the per-attachment endpoint fails. That
        // endpoint rejects `shared_from` outright, so for crew@ the raw MIME
        // (which the messages endpoint DOES proxy) is the only way in.
        $mimeBytes = null;

        foreach (array_slice($attachments, 0, 10) as $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($attachment['filename'] ?? '')) ?: 'attachment';
            $bytes = null;
            $status = null;

            // A shared mailbox goes straight to the raw MIME: the download
            // endpoint answers "invalid path" to shared_from every time, and
            // waiting out its retries costs seconds per file for nothing.
            if ($sharedFrom === null) {
                $response = $this->http(120)->get(
                    $this->url("/v3/grants/{$base['grant_id']}/attachments/{$id}/download"),
                    ['message_id' => $base['nylas_message_id']],
                );
                $status = $response->status();
                $bytes = ($response->successful() && $response->body() !== '') ? $response->body() : null;
            }

            if ($bytes === null) {
                $mimeBytes ??= $this->rawMimeAttachmentContents($base, $sharedFrom ?: null);
                $bytes = $mimeBytes[$name] ?? null;
            }

            if ($bytes === null) {
                Log::channel('submissions')->warning('Email leads: attachment download failed', [
                    'submission_id' => $submission->id,
                    'attachment_id' => $id,
                    'status' => $status,
                ]);

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = sprintf('email-leads/%d/%s%s', $submission->id, Str::uuid(), $extension !== '' ? '.'.$extension : '');

            Storage::disk('public')->put($path, $bytes);

            $stored[] = [
                'path' => $path,
                'name' => Str::limit($name, 120, ''),
                'mime' => strtolower((string) ($attachment['content_type'] ?? 'application/octet-stream')),
                'size' => strlen($bytes),
            ];
        }

        return $stored;
    }

    /**
     * Every attachment's bytes keyed by filename, from the message's raw
     * MIME. One request for the whole email — heavier than a per-attachment
     * download, but it works through `shared_from`.
     *
     * @return array<string, string>
     */
    protected function rawMimeAttachmentContents(array $base, ?string $sharedFrom): array
    {
        $response = $this->http(180)->get(
            $this->url("/v3/grants/{$base['grant_id']}/messages/{$base['nylas_message_id']}"),
            array_filter(['shared_from' => $sharedFrom, 'fields' => 'raw_mime']),
        );

        $encoded = (string) $response->json('data.raw_mime');
        if (! $response->successful() || $encoded === '') {
            return [];
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true) ?: base64_decode($encoded);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $contents = [];
            foreach (Message::fromString($raw)->getAttachments() as $part) {
                $filename = trim((string) $part->getFilename());
                if ($filename !== '') {
                    $contents[$filename] = $part->getContent();
                }
            }

            return $contents;
        } catch (\Throwable $e) {
            Log::channel('submissions')->warning('Email leads: raw MIME parse failed', [
                'message_id' => $base['nylas_message_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array<string, string> lowercased header name => value */
    protected function headerMap(array $message): array
    {
        $out = [];
        foreach ((array) ($message['headers'] ?? []) as $key => $header) {
            if (is_array($header) && isset($header['name'])) {
                $out[strtolower((string) $header['name'])] = (string) ($header['value'] ?? '');
            } elseif (is_string($key)) {
                $out[strtolower($key)] = (string) $header;
            }
        }

        return $out;
    }

    /**
     * Stable identity of an email, hashed to 40 chars. The RFC Message-ID is
     * preferred: it is the same whichever inbox or grant the mail is read
     * through, and hive keys its own email leads by exactly this value.
     */
    public function identity(string $rfcMessageId, string $nylasId): string
    {
        return $rfcMessageId !== ''
            ? sha1(strtolower(trim($rfcMessageId)))
            : sha1('nylas:'.$nylasId);
    }

    /** Prefer a plain-text part; fall back to stripping the HTML body. */
    protected function plainBody(array $message): string
    {
        $body = (string) ($message['body'] ?? '');
        if ($body === '') {
            return trim((string) ($message['snippet'] ?? ''));
        }

        if (! Str::contains($body, '<')) {
            return trim($body);
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Only look at mail newer than the newest message this mailbox's ledger
     * holds. The ledger is the watermark: a cache entry would do, but every
     * deploy clears the cache here (seen 2026-09-15), and a lost watermark
     * means re-reading the whole window each run. Before the first judged
     * message the lookback window applies — going live must not import
     * weeks of mail as fresh leads.
     */
    protected function since(string $mailbox): \DateTimeInterface
    {
        $newest = EmailLeadIngest::where('mailbox', $mailbox)->max('message_at');

        if ($newest) {
            // Small overlap: provider timestamps are not perfectly ordered
            // and the ledger makes re-reads free.
            return Carbon::parse($newest)->subMinutes(10);
        }

        return now()->subDays((int) config('services.email_leads.lookback_days', 2));
    }

    protected function http(int $timeout): PendingRequest
    {
        return Http::withToken((string) config('services.nylas.api_key'))
            ->timeout($timeout)
            ->retry(2, 2000, throw: false);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('services.nylas.api_uri', 'https://api.us.nylas.com'), '/').$path;
    }
}
