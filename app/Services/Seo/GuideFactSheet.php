<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\Site;
use App\Support\PermitGuideInfo;
use App\Support\SiteConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything a guide page (LandingPage template "guide") may be written from
 * — and nothing else. Every line here is something the site already states:
 * config/brand.php and config/geo-answers.php (the business), the service
 * page's own copy (config/sites/{site}/services-content.php, incl. the
 * six-stage process), the /costs guides (config/remodel-costs.php), the
 * researched town permit guides (config/permit-guides.php) and the adopted
 * building codes hive.contractors found for each town (AreaServed), the
 * how-to-choose checklist (config/competitors.php criteria) and the matched
 * projects' own records. GuidePageContentGenerator hands the text to the
 * model as the whole of what it may use, and then checks the draft's dollar
 * figures and durations against the same text.
 */
class GuideFactSheet
{
    /**
     * @param  array<string,mixed>  $cluster  one entry of content-guides.clusters
     * @param  Collection<int,Project>  $projects  the proof projects, areas loaded
     */
    public function build(array $cluster, Collection $projects): string
    {
        // A whole-house page (how long a renovation takes, what a gut renovation
        // costs) talks about every room, so it may list the other service
        // pages whose facts it needs — the model otherwise reaches for a
        // timeline it was not given and the figure guard rejects the draft.
        $extraServices = array_values(array_diff(
            array_unique(array_map('strval', (array) ($cluster['extra_services'] ?? []))),
            [(string) ($cluster['service'] ?? '')]
        ));

        $blocks = array_filter([
            $this->business(),
            $this->service((string) ($cluster['service'] ?? '')),
            ...array_map(fn (string $slug) => $this->service($slug), $extraServices),
            $this->process(),
            $this->costs((array) ($cluster['cost_guides'] ?? [])),
            $this->permits(in_array('permits', (array) ($cluster['facts'] ?? []), true)),
            $this->answerBank(),
            in_array('hiring', (array) ($cluster['facts'] ?? []), true) ? $this->hiring() : null,
            $this->projects($projects),
        ]);

        return implode("\n\n", $blocks);
    }

    private function business(): string
    {
        $brand = (array) config('brand', []);
        $meta = (array) config('geo-answers.meta', []);
        $lines = [
            '## The business',
            '- Name: '.($brand['name'] ?? 'GS Construction').' ('.($brand['legal_name'] ?? $brand['display_name'] ?? 'GS Construction & Remodeling').')',
            '- Owners: '.($brand['owners'] ?? 'Greg & Patryk Szady').', father and son; founded '.($brand['founded'] ?? 2015).'; 40+ years combined experience',
            '- Based in '.($brand['city'] ?? 'Prospect Heights').', '.($brand['state'] ?? 'IL').'; serves '.($meta['service_area'] ?? 'Chicago and the surrounding suburbs'),
            '- Languages spoken: '.implode(' and ', (array) ($meta['languages'] ?? ['English', 'Polish'])),
            '- Phone '.($brand['phone'] ?? '').' · '.($brand['email'] ?? ''),
            '- Site description: '.config('geo.site_description', ''),
        ];

        return implode("\n", $lines);
    }

    private function service(string $slug): ?string
    {
        $service = SiteConfig::forSite(Site::current(), 'services-content.services.'.$slug);
        if (! is_array($service) || $service === []) {
            return null;
        }

        $lines = ['## The service page: '.($service['title'] ?? $slug).' (/services/'.$slug.')'];
        if (! empty($service['heroSubtitle'])) {
            $lines[] = '- Promise: '.$service['heroSubtitle'];
        }
        if (! empty($service['description'])) {
            $lines[] = '- What the page says: '.$service['description'];
        }
        foreach ((array) ($service['facts'] ?? []) as $label => $fact) {
            $value = is_array($fact) ? trim(($fact['value'] ?? '').(! empty($fact['note']) ? ' — '.$fact['note'] : '')) : (string) $fact;
            $lines[] = "- {$label}: {$value}";
        }
        foreach ((array) ($service['features'] ?? []) as $feature) {
            if (! empty($feature['title'])) {
                $lines[] = '- What we do — '.$feature['title'].': '.($feature['description'] ?? '');
            }
        }
        if (! empty($service['included'])) {
            $lines[] = '- Included in our price: '.implode('; ', (array) $service['included']);
        }
        if (! empty($service['notIncluded'])) {
            $lines[] = '- Not included (quoted or bought separately): '.implode('; ', (array) $service['notIncluded']);
        }
        foreach ((array) ($service['faqs'] ?? []) as $faq) {
            if (! empty($faq['question']) && ! empty($faq['answer'])) {
                $lines[] = '- Q: '.$faq['question'].' A: '.$faq['answer'];
            }
        }

        return implode("\n", $lines);
    }

    private function process(): ?string
    {
        $steps = (array) SiteConfig::forSite(Site::current(), 'services-content.process', []);
        if ($steps === []) {
            return null;
        }

        $lines = ['## Our process on every project (from /process)'];
        foreach ($steps as $step) {
            $lines[] = sprintf(
                '%s. %s — %s%s',
                $step['step'] ?? count($lines),
                $step['title'] ?? '',
                $step['description'] ?? '',
                ! empty($step['time']) ? ' ('.$step['time'].')' : ''
            );
        }

        return implode("\n", $lines);
    }

    /** @param  list<string>  $slugs */
    private function costs(array $slugs): ?string
    {
        $guides = collect((array) config('remodel-costs.guides', []))
            ->filter(fn (array $guide) => in_array($guide['slug'] ?? '', $slugs, true));

        if ($guides->isEmpty()) {
            return null;
        }

        $lines = ['## Published cost guides (/costs — the only prices and timelines the site states)'];
        foreach ($guides as $guide) {
            $lines[] = '### '.($guide['h1'] ?? $guide['name'] ?? '').' (/costs/'.$guide['slug'].')';
            if (! empty($guide['answer'])) {
                $lines[] = '- Direct answer: '.$guide['answer'];
            }
            foreach ((array) ($guide['tiers'] ?? []) as $tier) {
                $lines[] = '- Tier — '.($tier['tier'] ?? '').': '.($tier['range'] ?? '').' — '.($tier['includes'] ?? '');
            }
            foreach ((array) ($guide['drivers'] ?? []) as $driver) {
                $lines[] = '- What moves the number — '.($driver['factor'] ?? '').': '.($driver['note'] ?? '');
            }
            if (! empty($guide['timeline'])) {
                $lines[] = '- Timeline: '.$guide['timeline'];
            }
            foreach ((array) ($guide['faq'] ?? []) as $faq) {
                $lines[] = '- Q: '.($faq['question'] ?? '').' A: '.($faq['answer'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    private function permits(bool $full): string
    {
        $lines = ['## Permits (what the site states)'];
        foreach ((array) config('geo-answers.answers', []) as $answer) {
            if (in_array('permits', (array) ($answer['topics'] ?? []), true)) {
                $lines[] = '- '.$answer['a'];
            }
        }
        $lines[] = '- Permits are pulled and managed by GS Construction; plan review with the village typically takes 1–2 weeks (process stage 4).';

        if (! $full) {
            return implode("\n", $lines);
        }

        $guides = PermitGuideInfo::all();
        if ($guides !== []) {
            $lines[] = '### Town permit guides (/permits — researched from each village\'s official pages)';
            foreach ($guides as $slug => $guide) {
                $town = (string) ($guide['town'] ?? Str::headline($slug));
                $parts = array_filter([
                    'when required: '.$this->sentences($guide['permit_when_required'] ?? '', 2),
                    'review time: '.$this->sentences($guide['review_time'] ?? '', 1),
                    'contractor registration: '.$this->sentences($guide['contractor_registration'] ?? '', 1),
                    'fees: '.$this->sentences($guide['fees'] ?? '', 1),
                    'inspections: '.$this->sentences($guide['inspections'] ?? '', 1),
                ], fn (string $part) => ! str_ends_with($part, ': '));
                $verified = ! empty($guide['researched_at']) ? ' (verified '.$guide['researched_at'].')' : '';
                $lines[] = "- {$town}{$verified}: ".implode(' | ', $parts);
            }
        }

        $areas = AreaServed::whereNotNull('building_codes')->orderBy('city')->get()
            ->filter(fn (AreaServed $area) => $area->buildingCodesList() !== []);
        if ($areas->isNotEmpty()) {
            $lines[] = '### Adopted building codes by town (as published by each village)';
            foreach ($areas as $area) {
                $note = filled($area->building_codes_note) ? ' — '.trim((string) $area->building_codes_note) : '';
                $lines[] = "- {$area->city}: ".implode(', ', $area->buildingCodesList()).$note;
            }
        }

        return implode("\n", $lines);
    }

    private function answerBank(): ?string
    {
        $answers = collect((array) config('geo-answers.answers', []))
            ->filter(fn (array $qa) => filled($qa['q'] ?? null) && filled($qa['a'] ?? null));

        if ($answers->isEmpty()) {
            return null;
        }

        $lines = ['## The site\'s own FAQ answers (/faq — quote freely)'];
        foreach ($answers as $qa) {
            $lines[] = '- Q: '.$qa['q'].' A: '.$qa['a'];
        }

        return implode("\n", $lines);
    }

    private function hiring(): ?string
    {
        $criteria = collect((array) config('competitors.criteria', []))
            ->filter(fn (array $c) => filled($c['label'] ?? null) && filled($c['why'] ?? null));

        if ($criteria->isEmpty()) {
            return null;
        }

        $lines = ['## How to choose a remodeling contractor (the site\'s own checklist, /how-to-choose-a-remodeling-contractor)'];
        foreach ($criteria as $c) {
            $us = filled($c['us'] ?? null) ? ' GS Construction: '.$c['us'] : '';
            $lines[] = '- '.$c['label'].': '.$c['why'].$us;
        }

        return implode("\n", $lines);
    }

    /** @param  Collection<int,Project>  $projects */
    private function projects(Collection $projects): ?string
    {
        if ($projects->isEmpty()) {
            return null;
        }

        $lines = ['## Completed GS Construction projects this page may reference (real; use the titles and towns exactly, never add others)'];
        foreach ($projects as $project) {
            $when = $project->completed_at ? ' — completed '.$project->completed_at->format('F Y') : '';
            $lines[] = sprintf(
                '- "%s" — %s%s — %s: %s',
                $project->title,
                $project->location ?: 'Chicago suburbs',
                $when,
                $project->typeLabel(),
                $this->trim((string) $project->description, 600)
            );
            foreach ($project->areas as $area) {
                /** @var ProjectArea $area */
                $label = $area->title ?: Str::headline((string) $area->project_type);
                $lines[] = '  - '.$label.' ('.$area->project_type.'): '.$this->trim((string) $area->description, 260);
            }
        }

        return implode("\n", $lines);
    }

    private function sentences(mixed $text, int $count): string
    {
        if (is_array($text)) {
            $text = implode(' ', array_map('strval', $text));
        }
        $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return trim(implode(' ', array_slice($parts, 0, $count)));
    }

    private function trim(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        return Str::limit($text, $max, '…');
    }
}
