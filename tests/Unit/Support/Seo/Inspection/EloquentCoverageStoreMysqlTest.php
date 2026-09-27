<?php

namespace Tests\Unit\Support\Seo\Inspection;

use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PDOException;
use PHPUnit\Framework\TestCase;
use SsSystems\Platform\Seo\Inspection\EloquentCoverageStore;

/**
 * Standalone models over this test's own throwaway tables — never this
 * site's real gsc_coverage_states/gsc_rich_result_issues, so a run can
 * never touch (or collide with) real dev data. Table names fixed (not
 * random) since setUp()/tearDown() fully drop-and-recreate them around
 * every test method.
 */
final class MysqlTestGscCoverageState extends Model
{
    protected $table = '_coverage_store_mysql_test_states';

    protected $guarded = [];

    protected $casts = [
        'last_crawl_time' => 'datetime',
        'inspected_at' => 'datetime',
        'last_changed_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];
}

final class MysqlTestGscRichResultIssue extends Model
{
    protected $table = '_coverage_store_mysql_test_issues';

    protected $guarded = [];

    protected $casts = [
        'inspected_at' => 'datetime',
    ];
}

/**
 * The kit's own tests/Unit/Seo/Inspection/EloquentCoverageStoreTest.php (see
 * its docblock) exercises the Carbon::parse($issue['inspected_at']) fix on
 * replaceRichResultIssues() only against in-memory SQLite, and says outright
 * that SQLite's text-affinity columns would accept a malformed datetime
 * string either way — proving the mechanism, not the MySQL behaviour it was
 * written for — and names THIS file as "the other half of this fix's
 * verification". This is that other half: a standalone Capsule connection
 * to gs.construction's own real MySQL server (this site's .env, read
 * directly — never the sqlite phpunit.xml forces on the app's default
 * connection for every other test in this repo), exercising the exact
 * shape UrlInspectionSweep::persistRichResults() hands the store:
 * Carbon::now()->toIso8601String(), e.g. "2026-09-27T19:33:25+00:00".
 *
 * What this actually found, run against this box's real server (MySQL
 * 8.0.44, sql_mode including STRICT_TRANS_TABLES): that literal round-trips
 * fine through EloquentCoverageStore either way, parsed or not — MySQL
 * 8.0.19+ parses a trailing UTC-offset temporal literal natively, so the
 * specific "MySQL rejects the raw string" failure the kit's docblock
 * describes could not be reproduced on this server (an older server, or one
 * running a stricter/different sql_mode, may still throw on the UNPARSED
 * raw string — proving that half needs a server this box doesn't have).
 * What this test DOES pin, on a real MySQL connection rather than SQLite's
 * forgiving text affinity: the fix's actual shipped code path —
 * Carbon::parse() before the raw insert — writes the exact right instant,
 * byte for byte, so the fix is safe to keep (version/driver independence)
 * whether or not this particular server ever needed it.
 *
 * Skips itself, rather than failing the suite, when this box's MySQL is not
 * reachable (a CI box or a worktree without the dev database) — every
 * other test in this repo runs on the sqlite :memory: connection
 * phpunit.xml forces, so this is the one file that must NOT run under that
 * override.
 */
final class EloquentCoverageStoreMysqlTest extends TestCase
{
    private ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $envPath = dirname(__DIR__, 5).'/.env';
        if (! is_file($envPath)) {
            $this->markTestSkipped('No .env to read real MySQL credentials from — nothing to verify against.');
        }

        $env = Dotenv::parse(file_get_contents($envPath));
        if (($env['DB_CONNECTION'] ?? null) !== 'mysql') {
            $this->markTestSkipped('This checkout\'s own .env is not configured for mysql — nothing to verify against.');
        }

        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'mysql',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? '3306',
            'database' => $env['DB_DATABASE'] ?? 'forge',
            'username' => $env['DB_USERNAME'] ?? 'forge',
            'password' => $env['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $container = new Container;
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        try {
            $this->capsule->getConnection()->getPdo();
        } catch (PDOException $e) {
            $this->capsule = null;
            $this->markTestSkipped('Real MySQL is not reachable from this worktree: '.$e->getMessage());
        }

        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        $schema->dropIfExists('_coverage_store_mysql_test_issues');
        $schema->dropIfExists('_coverage_store_mysql_test_states');

        $schema->create('_coverage_store_mysql_test_states', function ($table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('source', 40)->nullable();
            $table->string('console_reason', 191)->nullable();
            $table->string('verdict', 40)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('robots_txt_state', 60)->nullable();
            $table->string('indexing_state', 60)->nullable();
            $table->string('page_fetch_state', 60)->nullable();
            $table->string('sitemap_url', 2048)->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->string('user_canonical', 2048)->nullable();
            $table->string('google_canonical', 2048)->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
        });
        $schema->create('_coverage_store_mysql_test_issues', function ($table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('rich_result_type', 120)->nullable();
            $table->string('issue_severity', 40)->nullable();
            $table->string('issue_type', 191)->nullable();
            $table->text('issue_message')->nullable();
            $table->string('verdict', 40)->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if ($this->capsule !== null) {
            $schema = $this->capsule->getConnection()->getSchemaBuilder();
            $schema->dropIfExists('_coverage_store_mysql_test_issues');
            $schema->dropIfExists('_coverage_store_mysql_test_states');
        }

        parent::tearDown();
    }

    private function store(): EloquentCoverageStore
    {
        return new EloquentCoverageStore(
            MysqlTestGscCoverageState::class,
            MysqlTestGscRichResultIssue::class,
        );
    }

    public function test_the_shipped_carbon_parse_fix_writes_the_correct_instant_on_real_mysql(): void
    {
        $store = $this->store();

        // Exactly UrlInspectionSweep::persistRichResults()'s own shape: a
        // UTC ISO-8601 string with a literal "T" and a trailing "+00:00" —
        // never a DateTimeInterface, since the whole point of the fix is
        // that the STORE, not the sweep, does the parsing.
        $store->replaceRichResultIssues('https://gs.construction/projects', [[
            'rich_result_type' => 'Review snippet',
            'issue_severity' => 'ERROR',
            'issue_type' => 'MISSING_FIELD',
            'issue_message' => 'Missing field "name"',
            'verdict' => 'FAIL',
            'inspected_at' => '2026-09-27T19:33:25+00:00',
        ]]);

        $row = MysqlTestGscRichResultIssue::query()->where('url', 'https://gs.construction/projects')->first();

        $this->assertNotNull($row, 'the insert must actually land a row on real MySQL, not merely avoid an exception');
        $this->assertSame(
            '2026-09-27 19:33:25',
            $row->inspected_at->format('Y-m-d H:i:s'),
            'Carbon::parse() must resolve the UTC-offset literal to the exact same instant on a real MySQL connection, not just SQLite'
        );
    }

    public function test_upsert_also_round_trips_a_utc_offset_inspected_at_on_real_mysql(): void
    {
        $store = $this->store();

        $store->upsert([
            'url' => 'https://gs.construction/',
            'source' => 'sitemap',
            'console_reason' => null,
            'verdict' => 'PASS',
            'coverage_state' => 'Submitted and indexed',
            'robots_txt_state' => null,
            'indexing_state' => null,
            'page_fetch_state' => null,
            'sitemap_url' => null,
            'last_crawl_time' => null,
            'user_canonical' => null,
            'google_canonical' => null,
            'inspected_at' => '2026-09-27T19:33:25+00:00',
            'last_changed_at' => '2026-09-27T19:33:25+00:00',
            'consecutive_failures' => 0,
        ]);

        $found = $store->find('https://gs.construction/');

        $this->assertNotNull($found);
        $this->assertSame('2026-09-27T19:33:25+00:00', $found['inspected_at']);
    }
}
