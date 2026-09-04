<?php

namespace Tests\Feature\SeoIntel;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\SeoAction;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelStore;
use App\Services\Seo\SeoAutopilotService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Intelligence findings that carry an action hint become ledger actions of
 * the safe categories; hints outside the allowlist, outside the service area
 * or on pages the autopilot cannot rewrite stay findings.
 */
class IntelAutopilotTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_action_hints_become_safe_ledger_actions_linked_back_to_the_finding(): void
    {
        Cache::flush();
        config(['gbp-services.service_areas' => ['Wheeling, IL, USA', 'Palatine, IL, USA'], 'seo-intel.sources' => []]);
        $area = AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine', 'local_intro' => 'Short copy about Palatine.']);
        Project::create(['title' => 'Palatine Kitchen', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'is_featured' => true]);

        app(IntelStore::class)->saveFindings('onpage', 'r1', now()->toDateString(), [
            new Finding('onpage.duplicate_title', Finding::WARN, 'Duplicate title', 'Shares its title with another town page.', 'gs.construction', '/areas-served/palatine', [], ['type' => 'title_meta', 'path' => '/areas-served/palatine']),
            new Finding('onpage.thin', Finding::WARN, 'Thin page', 'Only 180 words.', 'gs.construction', '/areas-served/palatine', [], ['type' => 'content_refresh', 'path' => '/areas-served/palatine', 'phrases' => ['kitchen remodeling palatine']]),
            new Finding('labs.gap', Finding::INFO, 'Keyword gap', 'kitchen remodeling wheeling — 140/mo.', 'gs.construction', 'kitchen remodeling wheeling', [], ['type' => 'create_page', 'town' => 'Wheeling', 'service' => 'kitchen-remodeling', 'keyword' => 'kitchen remodeling wheeling']),
            new Finding('labs.gap', Finding::INFO, 'Keyword gap', 'out of area', 'gs.construction', 'kitchen remodeling round lake', [], ['type' => 'create_page', 'town' => 'Round Lake', 'service' => 'kitchen-remodeling']),
            new Finding('backlinks.broken', Finding::CRITICAL, 'Links point at a dead page', '3 links.', 'gs.construction', '/old-page', [], ['type' => 'reindex', 'url' => 'https://gs.construction/old-page']),
            new Finding('serp.ai', Finding::INFO, 'AI Overview appeared', '', 'gs.construction', 'kitchen remodeling', [], ['type' => 'redirect', 'from' => '/x', 'to' => '/y']),
            new Finding('onpage.other', Finding::WARN, 'Duplicate title on a static page', '', 'gs.construction', '/faq', [], ['type' => 'title_meta', 'path' => '/faq']),
        ]);

        $created = app(SeoAutopilotService::class)->synthesize();
        $intel = SeoAction::where('source', 'intel')->get();
        $this->assertGreaterThanOrEqual(4, $created);
        $this->assertEqualsCanonicalizing(['title_meta', 'content_refresh', 'create_page', 'reindex'], $intel->pluck('category')->unique()->values()->all());

        $title = $intel->firstWhere('category', 'title_meta');
        $this->assertSame('https://gs.construction/areas-served/palatine', $title->target_url);
        $this->assertSame(AreaServed::class, $title->target_type);
        $this->assertNotEmpty($title->payload['new_title']);
        $this->assertSame('onpage.duplicate_title', $title->payload['finding']);
        $this->assertSame(SeoAction::RISK_SAFE, $title->risk);
        $this->assertStringContainsString('Duplicate title', $title->hypothesis);

        $refresh = $intel->firstWhere('category', 'content_refresh');
        $this->assertSame($area->getKey(), (int) $refresh->target_id);
        $this->assertSame(['kitchen remodeling palatine'], $refresh->payload['phrases']);

        $page = $intel->firstWhere('category', 'create_page');
        $this->assertStringContainsString('Wheeling', $page->title);
        $this->assertNull($intel->first(fn ($x) => str_contains((string) $x->title, 'Round Lake')), 'towns outside the service area get no page');

        $this->assertSame('https://gs.construction/old-page', $intel->firstWhere('category', 'reindex')->target_url);
        $this->assertNull($intel->first(fn ($x) => $x->target_url === 'https://gs.construction/faq'), 'pages the resolver cannot map are left to a human');
        $this->assertSame(0, SeoAction::where('category', 'redirect')->count(), 'hints outside the allowlist are ignored');

        // Findings link back to the action that answers them; a second synthesize creates nothing new.
        $linked = DB::table('seo_intel_findings')->whereNotNull('seo_action_id')->count();
        $this->assertSame(4, $linked);
        $before = SeoAction::count();
        app(SeoAutopilotService::class)->synthesize();
        $this->assertSame($before, SeoAction::count(), 'fingerprints make the synthesis idempotent');
    }
}
