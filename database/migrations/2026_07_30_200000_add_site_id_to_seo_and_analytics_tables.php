<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 wave 2: scope the SEO / analytics / integration tables.
     *
     * The column is the easy half. The important half is the unique indexes:
     * several are keyed on values that are NOT site-specific, so a second site
     * would either collide on insert or silently merge into another site's row:
     *
     *   seo_path_overrides(path)   both sites have /contact
     *   tracked_404s(path)         both sites can 404 on the same path
     *   platform_settings(key)     same setting key per site
     *   oauth_tokens(provider)     each site needs its OWN Google/Bing token
     *   seo_actions(fingerprint)   fp(source,type,target) omits the site
     *   client_errors(fingerprint) sha256(kind|message|source|line) omits the site
     *   lead_filter_rules(...)     same rule text per site
     *
     * The GSC/Bing hashes already fold site_url into dim_hash, and the image
     * tables key on project_image_id (itself scoped), so those are correct
     * as-is — but site_id is still added to the index to make the intent
     * explicit rather than incidental.
     */
    protected array $columnOnly = [
        'gsc_coverage_state_history',
        'gsc_rich_result_issues',
        'seo_rank_snapshots',
        'tracked_events',
        'image_platform_uploads',
        'image_social_posts',
    ];

    /** table => [index name => columns the index should now cover] */
    protected array $reindex = [
        'bing_daily_totals' => ['bing_daily_totals_date_site_url_unique' => ['site_id', 'date', 'site_url']],
        'bing_traffic_stats' => ['bing_dim_hash_unique' => ['site_id', 'dim_hash']],
        'clarity_daily_metrics' => ['clarity_project_date_unique' => ['site_id', 'project_id', 'date']],
        'client_errors' => ['client_errors_fingerprint_unique' => ['site_id', 'fingerprint']],
        'gbp_daily_metrics' => ['gbp_metrics_unique' => ['site_id', 'date', 'location_id', 'metric']],
        'gbp_search_keywords' => ['gbp_keywords_unique' => ['site_id', 'location_id', 'keyword', 'year', 'month']],
        'gsc_coverage_states' => ['gsc_coverage_url_unique' => ['site_id', 'url']],
        'gsc_daily_totals' => ['gsc_daily_totals_date_site_url_unique' => ['site_id', 'date', 'site_url']],
        'gsc_query_metrics' => ['gsc_dim_hash_unique' => ['site_id', 'dim_hash']],
        'psi_snapshots' => ['psi_unique' => ['site_id', 'date', 'url', 'strategy']],
        'seo_actions' => ['seo_actions_fingerprint_unique' => ['site_id', 'fingerprint']],
        'seo_path_overrides' => ['seo_path_overrides_path_unique' => ['site_id', 'path']],
        'tracked_404s' => ['tracked_404s_path_unique' => ['site_id', 'path']],
        'lead_filter_rules' => ['lead_filter_rules_action_match_type_value_unique' => ['site_id', 'action', 'match_type', 'value']],
        'platform_settings' => ['platform_settings_key_unique' => ['site_id', 'key']],
        'oauth_tokens' => ['oauth_tokens_provider_unique' => ['site_id', 'provider']],
        'hive_project_zip_counts' => ['hpzc_zip_city_state_unique' => ['site_id', 'zip', 'city', 'state']],
    ];

    public function up(): void
    {
        $defaultId = (int) (DB::table('sites')->where('slug', config('sites.default', 'gsc'))->value('id') ?? 1);
        $tables = array_merge($this->columnOnly, array_keys($this->reindex));

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'site_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('site_id')->nullable()->after('id')->index()
                        ->constrained('sites')->nullOnDelete();
                });
            }

            DB::table($table)->whereNull('site_id')->update(['site_id' => $defaultId]);
        }

        // Rebuild the unique keys so they are unique *per site*.
        foreach ($this->reindex as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                // Schema::getIndexes(), not SHOW INDEX / ALTER ... DROP INDEX:
                // those are MySQL-only and broke the whole migration chain
                // under phpunit's SQLite.
                $exists = collect(Schema::getIndexes($table))
                    ->contains(fn ($idx) => $idx['name'] === $name);

                Schema::table($table, function (Blueprint $t) use ($exists, $name, $columns) {
                    if ($exists) {
                        $t->dropUnique($name);
                    }

                    $t->unique($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->reindex as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                $original = array_values(array_diff($columns, ['site_id']));
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                $cols = implode('`, `', $original);
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$name}` (`{$cols}`)");
            }
        }

        foreach (array_merge($this->columnOnly, array_keys($this->reindex)) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'site_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('site_id'));
            }
        }
    }
};
