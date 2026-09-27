<?php

namespace Tests\Feature\Seo;

use App\Models\Site;
use App\Support\Seo\TenantScopedReportStorage;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Console\ReportRun;
use Tests\TestCase;

/**
 * Kit 0.12.0: SsSystems\Platform\Reports\Console\ReportRun replaces this
 * app's own SeoReportRun. The kit class never hears about tenancy at all —
 * it only ever calls whatever Contracts\ReportStorage it is handed. Nothing
 * in this family had a regression test proving that binding (this app's own
 * TenantScopedReportStorage, wrapping SeoStorage::path()) actually keeps one
 * tenant's report run from reading or writing another's file — this is
 * that test, written before the port per the consolidation plan.
 *
 * seo:fake-write/seo:fake-broken are ad-hoc Artisan::command() registrations
 * (Http::fake()-style — no real report logic, no external call), the same
 * pattern SeoReportControllerTest already uses for this class.
 */
class ReportRunTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Artisan::command('seo:fake-write', function () {
            Storage::disk('local')->put(
                SeoStorage::path('reports/fake.md'),
                'written by '.(Site::current()?->slug ?? '?'),
            );
        });

        // Exits non-zero and writes nothing — the sharpest isolation probe:
        // if the tenant scope ever leaked, writtenSince() would find the
        // OTHER tenant's just-written file and misreport this as 'warning'
        // instead of 'failed'.
        Artisan::command('seo:fake-broken', function () {
            return 1;
        });
    }

    /** @return array{status: string, ok: bool, message: string} */
    private function runReport(Site $site, string $command): array
    {
        return Tenancy::for($site, fn () => ReportRun::run(
            'fake',
            ['label' => 'Fake report', 'command' => $command],
            Request::create('/'),
            14,
            ['site' => $site->slug],
            new TenantScopedReportStorage,
        ));
    }

    /**
     * ReportRun::run()'s returned array carries no 'file' key (only its log
     * context does — true of the original SeoReportRun::run() too, verified
     * against that class before this test was written), so the isolation
     * proof below reads storage state directly through the same
     * TenantScopedReportStorage path this app already trusts elsewhere.
     */
    private function reportPath(Site $site, string $key): string
    {
        return Tenancy::for($site, fn () => SeoStorage::path("reports/{$key}.md"));
    }

    public function test_one_tenants_write_is_invisible_to_the_other_and_leaves_its_file_alone(): void
    {
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();
        $jpeterson = Site::query()->where('slug', 'jpeterson')->firstOrFail();

        $gscRun = $this->runReport($gsc, 'seo:fake-write');

        $this->assertSame('ok', $gscRun['status']);
        $this->assertTrue(Storage::disk('local')->exists('reports/fake.md'));
        $this->assertSame('written by gsc', Storage::disk('local')->get('reports/fake.md'));
        // gsc is the default tenant: SeoStorage's legacy, unprefixed path.
        $this->assertSame('reports/fake.md', $this->reportPath($gsc, 'fake'));

        // jpeterson's run of a DIFFERENT, always-broken command must not see
        // gsc's file that was just written to the bare path.
        $jpRun = $this->runReport($jpeterson, 'seo:fake-broken');

        $this->assertSame('failed', $jpRun['status'], 'a leaked scope would report this as "warning" (gsc\'s fresh file)');
        $this->assertFalse($jpRun['ok']);
        $this->assertSame('tenants/jpeterson/reports/fake.md', $this->reportPath($jpeterson, 'fake'));
        $this->assertFalse(Storage::disk('local')->exists('tenants/jpeterson/reports/fake.md'));

        // gsc's file is completely untouched by jpeterson's run.
        $this->assertSame('written by gsc', Storage::disk('local')->get('reports/fake.md'));

        // Now jpeterson writes its own file — must land under its own prefix,
        // and must not overwrite or merge with gsc's.
        $jpWriteRun = $this->runReport($jpeterson, 'seo:fake-write');

        $this->assertSame('ok', $jpWriteRun['status']);
        $this->assertTrue(Storage::disk('local')->exists('tenants/jpeterson/reports/fake.md'));
        $this->assertSame('written by jpeterson', Storage::disk('local')->get('tenants/jpeterson/reports/fake.md'));
        $this->assertSame('written by gsc', Storage::disk('local')->get('reports/fake.md'), "gsc's file must survive jpeterson's write");
    }
}
