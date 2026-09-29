<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Owner's call, 2026-09-29: search engines and AI assistants that answer
 * people (and cite the site) may crawl; crawlers that collect pages to train
 * models may not. A crawler reads every group naming it together, and Google
 * lets "Allow: /" beat "Disallow: /" at the same length, so a leftover Allow
 * group for a training crawler would quietly undo the block.
 */
class AiCrawlerRobotsTest extends TestCase
{
    private const TRAINING = ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'cohere-ai'];

    private const ANSWERING = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'Googlebot', 'Bingbot', 'Amazonbot'];

    /** @return array<string, list<string>> lowercase user-agent => its rules, every group naming it combined */
    private function robotsGroups(string $robots): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                $groups[strtolower($value)] ??= [];
            } elseif (in_array($field, ['allow', 'disallow'], true) && $agents !== []) {
                $inRules = true;
                foreach ($agents as $agent) {
                    $groups[$agent][] = "{$field}: {$value}";
                }
            }
        }

        return $groups;
    }

    /** @return list<string> the rules a crawler obeys: its own groups, else the * group */
    private function rulesFor(array $groups, string $agent): array
    {
        return $groups[strtolower($agent)] ?? $groups['*'] ?? [];
    }

    /** @dataProvider liveSites */
    public function test_training_crawlers_are_kept_out_and_answering_crawlers_are_not(string $url): void
    {
        $groups = $this->robotsGroups($this->get($url)->assertOk()->getContent());

        foreach (self::TRAINING as $agent) {
            $rules = $this->rulesFor($groups, $agent);
            $this->assertContains('disallow: /', $rules, "{$agent} should be disallowed");
            $this->assertNotContains('allow: /', $rules, "{$agent} has an Allow: / that undoes the block");
        }

        foreach (self::ANSWERING as $agent) {
            $this->assertNotContains('disallow: /', $this->rulesFor($groups, $agent), "{$agent} should not be shut out");
        }
    }

    public static function liveSites(): array
    {
        return [
            'gs.construction' => ['https://gs.construction/robots.txt'],
            'a tenant on the default template' => ['https://jpeterson-design.com/robots.txt'],
        ];
    }
}
