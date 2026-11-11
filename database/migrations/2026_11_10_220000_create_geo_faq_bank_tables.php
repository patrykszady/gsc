<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The FAQ bank: questions and answers derived from the site's own pages
 * for the AI answer engines, behind `/geo/answers.json` and llms-full.txt
 * (Patryk, 2026-11-10). Written by SsSystems\Platform\Seo\Faq\Bank\
 * FaqBankBuilder (`seo:faq-bank`) through FaqBankStore, read by
 * FaqBankCatalog for the feed — see App\Support\Seo\FaqBank\
 * GscFaqBankSource for which of this site's pages are mined.
 *
 *   - geo_faq_bank_pages: one row per mined page — the sha1 of the text it
 *     was built from (so an unchanged page costs nothing on the next run),
 *     when it was built, when it was last looked at, how many answers it
 *     holds, why the last generation failed.
 *   - geo_faq_bank: one row per answer, keyed back to its page by
 *     url_hash, with the keys (q_key/a_key: lower case, towns collapsed to
 *     {town}) the builder dedupes on across pages.
 *
 * Copied from vendor/ss-systems/platform-kit/resources/stubs/seo/
 * create_geo_faq_bank_tables.php.stub (kit 0.24.0) with its multi-tenant
 * note applied: gs.construction is multi-tenant, so site_id stays and is
 * part of the pages' unique index — AppServiceProvider passes
 * `tenantColumn: 'site_id'` to FaqBankStore, the same convention as
 * 2026_11_10_170100_create_bing_url_info_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_faq_bank_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->char('url_hash', 40);
            $table->string('url', 500);
            $table->string('kind', 40);
            $table->string('source_hash', 40)->nullable();
            $table->unsignedInteger('answers')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('checked_at');
            $table->unique(['site_id', 'url_hash']);
        });

        Schema::create('geo_faq_bank', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->char('url_hash', 40);
            $table->string('source_url', 500);
            $table->string('kind', 40);
            $table->text('question');
            $table->text('answer');
            $table->string('q_key', 191);
            $table->string('a_key', 191);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index('site_id');
            $table->index('url_hash');
            $table->index('q_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_faq_bank');
        Schema::dropIfExists('geo_faq_bank_pages');
    }
};
