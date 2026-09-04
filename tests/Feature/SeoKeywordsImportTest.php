<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoKeywordsImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_imports_a_local_falcon_list_and_classifies_it(): void
    {
        AreaServed::create(['city' => 'Glencoe', 'slug' => 'glencoe']);
        $file = tempnam(sys_get_temp_dir(), 'kw');
        file_put_contents($file, "keyword,volume,difficulty\nsmall bathroom remodel glencoe,40,18\n\"who does kitchen renovations near glencoe il\"\n");

        $this->artisan('seo:keywords-import', ['file' => $file])->assertExitCode(0);

        $a = DB::table('seo_keywords')->where('keyword', 'small bathroom remodel glencoe')->first();
        $this->assertSame(40, (int) $a->volume);
        $this->assertSame('bathroom-remodeling', $a->service);
        $this->assertSame('Glencoe', $a->city);
        $this->assertSame('small-space', $a->modifier);
        $this->assertSame(['localfalcon'], json_decode($a->sources, true));

        $b = DB::table('seo_keywords')->where('keyword', 'who does kitchen renovations near glencoe il')->first();
        $this->assertNull($b->volume);
        $this->assertSame('kitchen-remodeling', $b->service);
    }
}
