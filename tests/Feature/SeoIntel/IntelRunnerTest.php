<?php

namespace Tests\Feature\SeoIntel;

use App\Services\DataForSeoService;
use App\Services\Seo\Intel\Finding;
use App\Services\Seo\Intel\IntelRunner;
use App\Services\Seo\Intel\IntelSource;
use App\Services\Seo\Intel\IntelStore;
use App\Services\Seo\Intel\Snapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A source whose score the test dials, to exercise the store/diff/finding lifecycle. */
class FakeScoreSource extends IntelSource
{
    public static int $score = 80;

    public static bool $explode = false;

    public static bool $nothing = false;

    public function family(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake score';
    }

    public function estimateCost(): float
    {
        return 0.01;
    }

    public function collect(): array
    {
        if (self::$explode) {
            throw new \RuntimeException('API said no');
        }
        if (self::$nothing) {
            return $this->skip();
        }
        $this->dfs->request('POST', '/fake/live', [['x' => 1]]);

        return [new Snapshot('summary', $this->ourDomain(), ['score' => self::$score], ['pages' => 12])];
    }

    public function findings(): array
    {
        $now = $this->latest('summary', $this->ourDomain());
        $prev = $this->previous('summary', $this->ourDomain());
        if ($now && $prev && $now['metrics']['score'] < $prev['metrics']['score']) {
            return [$this->finding('score_drop', Finding::WARN, 'Score dropped', 'From ' . $prev['metrics']['score'] . ' to ' . $now['metrics']['score'], $this->ourDomain(), null,
                ['score' => ['prev' => $prev['metrics']['score'], 'now' => $now['metrics']['score']]], ['type' => 'title_meta', 'path' => '/'])];
        }

        return [];
    }

    public function report(): array
    {
        $now = $this->latest('summary', $this->ourDomain());

        return ['tiles' => [['label' => 'Score', 'value' => $now['metrics']['score'] ?? null]], 'tables' => [], 'note' => 'fake'];
    }
}

class IntelRunnerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static float $balance = 12.5;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://gs.construction', 'services.dataforseo.login' => 'u', 'services.dataforseo.password' => 'p', 'seo-intel.sources' => [FakeScoreSource::class, '\\App\\Services\\Seo\\Intel\\Sources\\DoesNotExistSource']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'user_data')) {
                return Http::response(['tasks' => [['result' => [['money' => ['balance' => self::$balance]]]]]]);
            }

            return Http::response(['tasks' => [['cost' => 0.004, 'status_code' => 20000, 'result' => [['ok' => true]]]]]);
        });
        FakeScoreSource::$score = 80;
        FakeScoreSource::$explode = false;
        FakeScoreSource::$nothing = false;
        self::$balance = 12.5;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registry_skips_missing_classes_and_the_command_lists_families(): void
    {
        $runner = app(IntelRunner::class);
        $this->assertSame(['fake'], array_keys($runner->sources()));
        $this->artisan('seo:intel', ['--list' => true])->expectsOutputToContain('Fake score')->assertExitCode(0);
        $this->artisan('seo:intel', ['family' => ['nope']])->assertExitCode(1);
    }

    public function test_runs_store_snapshots_open_findings_on_a_drop_and_resolve_them_on_recovery(): void
    {
        Carbon::setTestNow('2026-09-05 06:00:00');
        $this->artisan('seo:intel', ['family' => ['fake'], '--budget' => 1])->assertExitCode(0);
        $this->assertSame(1, DB::table('seo_intel_snapshots')->count());
        $this->assertSame(0, DB::table('seo_intel_findings')->count());
        $run = DB::table('seo_intel_runs')->first();
        $this->assertEquals(0.004, (float) $run->cost);
        $this->assertSame(1, (int) $run->snapshots);

        // The command's budget guard reads the runner's transport, not a second instance.
        $this->artisan('seo:intel', ['family' => ['fake'], '--budget' => 1])->expectsOutputToContain('Spent $0.004')->assertExitCode(0);
        $this->artisan('seo:intel', ['family' => ['fake'], '--budget' => 0.001])->expectsOutputToContain('Spent $0.004');

        Carbon::setTestNow('2026-09-12 06:00:00');
        FakeScoreSource::$score = 70;
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(0);
        $f = DB::table('seo_intel_findings')->first();
        $this->assertSame('fake.score_drop', $f->code);
        $this->assertSame('warn', $f->severity);
        $this->assertSame('2026-09-12', substr((string) $f->first_seen_on, 0, 10));
        $this->assertNull($f->resolved_at);
        $this->assertSame(['score' => ['prev' => 80, 'now' => 70]], json_decode((string) $f->delta, true));
        $this->assertSame('title_meta', json_decode((string) $f->action, true)['type']);

        // Still down a week later: same row, kept open, last_seen moves.
        Carbon::setTestNow('2026-09-19 06:00:00');
        FakeScoreSource::$score = 65;
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(0);
        $this->assertSame(1, DB::table('seo_intel_findings')->count());
        $this->assertSame('2026-09-19', substr((string) DB::table('seo_intel_findings')->value('last_seen_on'), 0, 10));
        $this->assertSame('2026-09-12', substr((string) DB::table('seo_intel_findings')->value('first_seen_on'), 0, 10));

        // Recovered: resolved, and the store's previous() looks at the run before.
        Carbon::setTestNow('2026-09-26 06:00:00');
        FakeScoreSource::$score = 90;
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(0);
        $this->assertNotNull(DB::table('seo_intel_findings')->value('resolved_at'));
        $store = app(IntelStore::class);
        $this->assertSame(90, $store->latest('fake', 'summary', 'gs.construction')['metrics']['score']);
        $this->assertSame(65, $store->previous('fake', 'summary', 'gs.construction')['metrics']['score']);
        $this->assertSame(4, DB::table('seo_intel_runs')->count());
        $this->assertSame(1, (int) DB::table('seo_intel_runs')->orderByDesc('id')->value('findings_resolved'));
    }

    public function test_a_source_that_throws_is_recorded_as_a_failed_run_without_stopping_the_others(): void
    {
        FakeScoreSource::$explode = true;
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(1);
        $run = DB::table('seo_intel_runs')->first();
        $this->assertStringContainsString('API said no', (string) $run->error);
        $this->assertSame(0, DB::table('seo_intel_snapshots')->count());
    }

    public function test_dry_run_and_findings_only_do_not_write_snapshots(): void
    {
        $this->artisan('seo:intel', ['family' => ['fake'], '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('seo_intel_snapshots')->count());
        $this->assertSame(0, DB::table('seo_intel_runs')->count());
        $live = fn () => Http::recorded(fn ($r) => str_contains($r->url(), '/fake/live'))->count();
        $before = $live();
        $this->assertSame(1, $before, 'a dry run still collects');
        $this->artisan('seo:intel', ['family' => ['fake'], '--findings' => true])->assertExitCode(0);
        $this->assertSame($before, $live(), 'findings-only makes no API calls');
    }

    public function test_reset_wipes_a_family_and_needs_explicit_names(): void
    {
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(0);
        $this->assertSame(1, DB::table('seo_intel_snapshots')->count());
        $this->artisan('seo:intel', ['--reset' => true, '--findings' => true])->assertExitCode(1);
        $this->assertSame(1, DB::table('seo_intel_snapshots')->count(), 'no names, nothing removed');
        $this->artisan('seo:intel', ['family' => ['fake'], '--reset' => true, '--findings' => true])->expectsOutputToContain('removed 1 snapshots')->assertExitCode(0);
        $this->assertSame(0, DB::table('seo_intel_snapshots')->count());
        $this->assertSame(0, DB::table('seo_intel_runs')->count());
    }

    public function test_a_source_with_nothing_to_do_leaves_no_ledger_row(): void
    {
        FakeScoreSource::$nothing = true;
        $this->artisan('seo:intel', ['family' => ['fake']])->expectsOutputToContain('nothing to collect')->assertExitCode(0);
        $this->assertSame(0, DB::table('seo_intel_runs')->count());
    }

    public function test_balance_guard_refuses_a_run_the_account_cannot_cover(): void
    {
        self::$balance = 0.001;
        $this->artisan('seo:intel', ['family' => ['fake']])->assertExitCode(1);
        $this->assertSame(0, DB::table('seo_intel_runs')->count());
    }

    public function test_poll_until_returns_the_probe_value_or_null_when_it_never_settles(): void
    {
        $dfs = app(DataForSeoService::class);
        $n = 0;
        $this->assertSame('done', $dfs->pollUntil(function () use (&$n) {
            return ++$n >= 3 ? 'done' : null;
        }));
        $this->assertSame(3, $n);
        $this->assertNull($dfs->pollUntil(fn () => null));
    }
}
