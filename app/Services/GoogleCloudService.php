<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google Cloud REST client authenticated as the platform's service
 * account — no SDK dependency, just the RS256 JWT-bearer flow signed with
 * openssl against the key JSON.
 *
 * Exists for per-site Search Console BigQuery export provisioning: ONE
 * platform-owned GCP project, one dataset per tenant site. Everything Google
 * Cloud-side (API enablement, the exporter's IAM grant, dataset creation) is
 * automated here; the only remaining step per site is the 3-field form in
 * Search Console, which has no API and accepts only a verified property
 * owner (checked against current docs 2026-08-24).
 */
class GoogleCloudService
{
    protected ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isConfigured(): bool
    {
        return (string) config('services.gcp.project_id') !== ''
            && is_file((string) config('services.gcp.credentials'));
    }

    public function projectId(): string
    {
        return (string) config('services.gcp.project_id');
    }

    /** Service-account access token via the JWT-bearer grant. Cached ~50 min. */
    protected function token(): ?string
    {
        return Cache::remember('gcp_sa_token', 3000, function (): ?string {
            $key = json_decode((string) file_get_contents((string) config('services.gcp.credentials')), true);
            if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
                $this->lastError = 'Credentials JSON unreadable or missing client_email/private_key';

                return null;
            }

            $now = time();
            $b64 = fn (array $d): string => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
            $unsigned = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            if (! openssl_sign($unsigned, $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) {
                $this->lastError = 'openssl_sign failed on the service-account key';

                return null;
            }

            $resp = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '='),
            ]);

            if (! $resp->successful()) {
                $this->lastError = 'Token exchange: ' . mb_substr($resp->body(), 0, 200);

                return null;
            }

            return $resp->json('access_token');
        });
    }

    protected function call(string $method, string $url, ?array $body = null): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        $req = Http::withToken($token)->acceptJson()->timeout(30);
        $resp = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);

        if (! $resp->successful()) {
            $this->lastError = "HTTP {$resp->status()} {$url}: " . mb_substr($resp->body(), 0, 300);

            return null;
        }

        return (array) $resp->json();
    }

    /** Idempotent: enabling an already-enabled service succeeds. */
    public function enableBigQuery(): bool
    {
        return $this->call('POST', sprintf(
            'https://serviceusage.googleapis.com/v1/projects/%s/services/bigquery.googleapis.com:enable',
            $this->projectId()
        ), []) !== null;
    }

    /**
     * Grant Search Console's exporter account the two documented roles.
     * Read-modify-write on the project IAM policy; idempotent by member check.
     */
    public function grantSearchConsoleExporter(): bool
    {
        $member = 'serviceAccount:search-console-data-export@system.gserviceaccount.com';
        $needed = ['roles/bigquery.jobUser', 'roles/bigquery.dataEditor'];

        $policy = $this->call('POST', sprintf(
            'https://cloudresourcemanager.googleapis.com/v1/projects/%s:getIamPolicy',
            $this->projectId()
        ), []);
        if ($policy === null) {
            return false;
        }

        $bindings = $policy['bindings'] ?? [];
        $changed = false;
        foreach ($needed as $role) {
            $idx = null;
            foreach ($bindings as $i => $b) {
                if (($b['role'] ?? '') === $role) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                $bindings[] = ['role' => $role, 'members' => [$member]];
                $changed = true;
            } elseif (! in_array($member, $bindings[$idx]['members'] ?? [], true)) {
                $bindings[$idx]['members'][] = $member;
                $changed = true;
            }
        }

        if (! $changed) {
            return true;
        }

        $policy['bindings'] = $bindings;

        return $this->call('POST', sprintf(
            'https://cloudresourcemanager.googleapis.com/v1/projects/%s:setIamPolicy',
            $this->projectId()
        ), ['policy' => $policy]) !== null;
    }

    /** Create a US-located dataset if absent. Returns true when it exists after the call. */
    public function ensureDataset(string $datasetId): bool
    {
        $get = $this->call('GET', sprintf(
            'https://bigquery.googleapis.com/bigquery/v2/projects/%s/datasets/%s',
            $this->projectId(),
            $datasetId
        ));
        if ($get !== null) {
            return true;
        }

        return $this->call('POST', sprintf(
            'https://bigquery.googleapis.com/bigquery/v2/projects/%s/datasets',
            $this->projectId()
        ), [
            'datasetReference' => ['projectId' => $this->projectId(), 'datasetId' => $datasetId],
            'location' => 'US',
            'description' => 'Search Console bulk export (per-site, provisioned by bigquery:provision)',
        ]) !== null;
    }
}
