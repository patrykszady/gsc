<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The platform kit's `seo:ai-mentions` stores the pages an AI engine cited
 * per answer in `seo_ai_mentions.cited_sources`, but only when that column
 * exists — a site whose migrations are behind silently keeps nothing. This
 * proves the column is there once the migrations have run.
 */
class SeoAiMentionsCitedSourcesTest extends TestCase
{
    public function test_the_cited_sources_column_exists_after_migrating(): void
    {
        $this->assertTrue(Schema::hasTable('seo_ai_mentions'));
        $this->assertTrue(Schema::hasColumn('seo_ai_mentions', 'cited_sources'));
    }
}
