<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminating middleware that counts AI-driven traffic into ai_traffic_daily:
 *
 *  - "referral": a human clicked through from an AI assistant (Referer header
 *    from chatgpt.com, perplexity.ai, …). This is the GEO conversion metric —
 *    the AI answered with us and someone cared enough to visit.
 *  - "crawler": an AI bot fetched a page (user-agent match). This is the GEO
 *    supply metric — whether assistants are reading the site at all.
 *
 * Same shape as Track404Responses: work happens in terminate() after the
 * response is sent, one cheap upsert per matching request, and nothing here
 * may ever break the response cycle.
 */
class TrackAiTraffic
{
    /** Referer host fragment => source label. */
    private const REFERRERS = [
        'chatgpt.com' => 'chatgpt',
        'chat.openai.com' => 'chatgpt',
        'perplexity.ai' => 'perplexity',
        'copilot.microsoft.com' => 'copilot',
        'gemini.google.com' => 'gemini',
        'claude.ai' => 'claude',
        'you.com' => 'you.com',
        'phind.com' => 'phind',
        'poe.com' => 'poe',
        'duckduckgo.com/aichat' => 'duckduckgo-ai',
    ];

    /** User-agent fragment => bot label. Order matters: first match wins. */
    private const CRAWLERS = [
        'OAI-SearchBot' => 'OAI-SearchBot',
        'ChatGPT-User' => 'ChatGPT-User',
        'GPTBot' => 'GPTBot',
        'Perplexity-User' => 'Perplexity-User',
        'PerplexityBot' => 'PerplexityBot',
        'Claude-User' => 'Claude-User',
        'Claude-SearchBot' => 'Claude-SearchBot',
        'ClaudeBot' => 'ClaudeBot',
        'claude-web' => 'Claude-Web',
        'Google-Extended' => 'Google-Extended',
        'GoogleOther' => 'GoogleOther',
        'Applebot-Extended' => 'Applebot-Extended',
        'Applebot' => 'Applebot',
        'meta-externalagent' => 'Meta-External',
        'FacebookBot' => 'FacebookBot',
        'Amazonbot' => 'Amazonbot',
        'Bytespider' => 'Bytespider',
        'cohere-ai' => 'Cohere',
        'YouBot' => 'YouBot',
        'MistralAI' => 'MistralAI',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $request->isMethod('GET') || $response->getStatusCode() >= 400) {
                return;
            }

            $path = '/' . ltrim($request->path(), '/');
            if (str_starts_with($path, '/admin')
                || str_starts_with($path, '/livewire')
                || str_starts_with($path, '/horizon')
                || str_starts_with($path, '/storage')
                || str_starts_with($path, '/build')) {
                return;
            }

            [$kind, $source] = $this->classify($request);
            if ($kind === null) {
                return;
            }

            $siteId = \App\Models\Site::current()->id;

            // Increment-or-insert without read-modify-write races. The tiny
            // duplicate-insert race between the two statements is absorbed by
            // the unique key: the insert fails, the count is off by at most
            // one request, and nothing surfaces to the visitor.
            $updated = DB::table('ai_traffic_daily')
                ->where('site_id', $siteId)
                ->where('date', now()->toDateString())
                ->where('kind', $kind)
                ->where('source', $source)
                ->increment('count');

            if ($updated === 0) {
                try {
                    DB::table('ai_traffic_daily')->insert([
                        'site_id' => $siteId,
                        'date' => now()->toDateString(),
                        'kind' => $kind,
                        'source' => $source,
                        'count' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Illuminate\Database\QueryException) {
                    DB::table('ai_traffic_daily')
                        ->where('site_id', $siteId)
                        ->where('date', now()->toDateString())
                        ->where('kind', $kind)
                        ->where('source', $source)
                        ->increment('count');
                }
            }
        } catch (\Throwable) {
            // Analytics must never break a page.
        }
    }

    /** @return array{0:?string,1:?string} [kind, source] */
    private function classify(Request $request): array
    {
        $ua = (string) $request->userAgent();
        foreach (self::CRAWLERS as $fragment => $label) {
            if ($ua !== '' && stripos($ua, $fragment) !== false) {
                return ['crawler', $label];
            }
        }

        $referer = strtolower((string) $request->headers->get('referer'));
        if ($referer !== '') {
            foreach (self::REFERRERS as $fragment => $label) {
                if (str_contains($referer, $fragment)) {
                    return ['referral', $label];
                }
            }
        }

        return [null, null];
    }
}
