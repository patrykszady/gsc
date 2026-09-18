<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The hive.contractors connection as a platform: where the site sends its
 * leads, and which of the business's hive-connected mailboxes it reads for
 * email inquiries. Set up from the central admin's Platforms page; the
 * mailboxes and their switches live on its Leads page.
 *
 * The API token is stored encrypted (PlatformSetting) and never returned —
 * only whether one is set and a short fingerprint that changes with it.
 */
class PlatformsHiveController extends Controller
{
    use BuildsApiResponses;

    /** GET platforms/hive — connection plus the mailboxes, checked against hive now. */
    public function show(): JsonResponse
    {
        return $this->itemResponse($this->payload(live: true));
    }

    /** POST platforms/hive/credentials */
    public function saveCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:255'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        PlatformSetting::put(HiveProjectsClient::SETTING_URL, rtrim((string) $data['url'], '/'));

        if (filled($data['token'] ?? null)) {
            PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, (string) $data['token']);
        }

        app(HiveProjectsClient::class)->forgetMailboxes();

        return $this->itemResponse($this->payload(live: true));
    }

    /** DELETE platforms/hive — forget the connection; the env fallback, if any, applies again. Nothing to check live: what is on file is the answer. */
    public function disconnect(): JsonResponse
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, null);
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, null);
        app(HiveProjectsClient::class)->forgetMailboxes();

        return $this->itemResponse($this->payload(live: false));
    }

    /** PUT platforms/hive/mailboxes — which mailboxes are read. */
    public function saveMailboxes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'disabled' => ['present', 'array', 'max:50'],
            'disabled.*' => ['string', 'email', 'max:255'],
        ]);

        EmailLeadReader::setDisabledMailboxes($data['disabled']);

        return $this->itemResponse($this->payload(live: true));
    }

    /** POST platforms/hive/mailboxes/read — read the mailboxes now rather than at the next five-minute run. */
    public function readNow(): JsonResponse
    {
        $result = app(EmailLeadReader::class)->ingest();
        unset($result['details']);

        return $this->itemResponse(['run' => $result] + $this->payload(live: true));
    }

    /**
     * The read model. With $live the mailboxes are fetched from hive (through
     * the client's half-hour cache) and a failure is reported as the
     * connection's error; without, only what is on file is returned.
     *
     * @return array<string, mixed>
     */
    public function payload(bool $live): array
    {
        $client = app(HiveProjectsClient::class);
        $reader = app(EmailLeadReader::class);

        $error = null;
        $connected = null;

        if ($live && $client->isConfigured()) {
            try {
                $client->mailboxes();
                $connected = true;
            } catch (\Throwable $e) {
                $connected = false;
                $error = $e->getMessage();
            }
        }

        return [
            'configured' => $client->isConfigured(),
            'connected' => $connected,
            'error' => $error,
            'url' => $client->isConfigured() ? $client->baseUrl() : null,
            'stored_in' => PlatformSetting::get(HiveProjectsClient::SETTING_TOKEN) ? 'settings' : ($client->isConfigured() ? 'env' : null),
            'token_set' => $client->isConfigured(),
            'token_fingerprint' => $client->tokenFingerprint(),
            'mailboxes' => $reader->mailboxStatus(live: $live),
        ];
    }
}
