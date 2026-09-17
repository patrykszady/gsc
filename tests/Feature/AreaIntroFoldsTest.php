<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Services\AiContentService;
use Tests\TestCase;

/**
 * A town's long copy renders as a short lead plus an accordion: "how the town
 * was built" and "what that means for remodeling" once seo:split-area-intros
 * has sorted it, one "more" fold until then, and the permit notes as a fold
 * of their own. Palatine's 478 words sat in a single block (2026-09-17).
 */
class AreaIntroFoldsTest extends TestCase
{
    private function palatine(): AreaServed
    {
        return AreaServed::create([
            'city' => 'Palatine', 'slug' => 'palatine', 'state' => 'IL',
            'intro' => 'GS Construction remodels homes across Palatine.',
            'local_intro' => "Palatine's housing maps its growth rings with remarkable clarity, reflecting over a century and a half of expansion. The village's core was platted in 1855, centered around today's downtown Metra station on the UP-NW line. As the village expanded, 1920s subdivisions like Palatine Manor emerged. The true postwar boom built the majority of Palatine.\n\nThese homes often present opportunities for significant layout improvements; kitchen walls frequently come down to create open-concept living spaces. Exposing engineered wood floor joists can sometimes trigger a residential sprinkler requirement.",
            'permit_notes' => 'The Village of Palatine requires building permits for remodeling work and appearance review for additions.',
        ]);
    }

    public function test_without_a_split_the_first_sentences_lead_and_the_rest_folds_once(): void
    {
        $area = $this->palatine();
        $folds = $area->introFolds();

        $this->assertSame("Palatine's housing maps its growth rings with remarkable clarity, reflecting over a century and a half of expansion. The village's core was platted in 1855, centered around today's downtown Metra station on the UP-NW line.", $folds['lead']);
        $this->assertCount(1, $folds['folds']);
        $this->assertSame('More about the homes in Palatine', $folds['folds'][0]['heading']);
        $this->assertStringStartsWith('As the village expanded, 1920s subdivisions', $folds['folds'][0]['body']);
        $this->assertStringContainsString("\n\nThese homes often present", $folds['folds'][0]['body'], 'the paragraph break survives');

        $page = $this->get('/areas-served/palatine')->assertOk();
        $page->assertSee('More about the homes in Palatine');
        $page->assertSee('Palatine permits &amp; building codes for remodeling projects', false);
        $page->assertSee('hidden="until-found"', false);
        $page->assertSee('trigger a residential sprinkler requirement', 'folded copy is still in the DOM for Google');
        $page->assertSee('appearance review for additions');
    }

    public function test_a_current_split_gives_the_history_and_potential_folds_and_an_edit_retires_it(): void
    {
        $area = $this->palatine();
        $area->forceFill(['intro_folds' => [
            'lead' => 'Palatine grew in rings from an 1855 core to postwar subdivisions.',
            'history' => "The village's core was platted in 1855 around the Metra station. 1920s subdivisions like Palatine Manor followed, then the postwar boom.",
            'potential' => 'Kitchen walls come down for open-concept living; exposed engineered joists can trigger a sprinkler requirement.',
            'source_hash' => AreaServed::introHash($area->local_intro),
        ]])->save();

        $folds = $area->fresh()->introFolds();
        $this->assertSame('Palatine grew in rings from an 1855 core to postwar subdivisions.', $folds['lead']);
        $this->assertSame(['How Palatine was built', 'What that means for remodeling in Palatine'], array_column($folds['folds'], 'heading'));
        $this->assertTrue($area->fresh()->hasCurrentIntroSplit());

        $page = $this->get('/areas-served/palatine')->assertOk();
        $page->assertSeeInOrder(['How Palatine was built', 'What that means for remodeling in Palatine', 'Palatine permits']);
        $page->assertDontSee('More about the homes in Palatine');

        // Edited in admin: the stored split no longer describes the copy.
        $area->update(['local_intro' => $area->local_intro.' A new sentence about Palatine.']);
        $this->assertFalse($area->fresh()->hasCurrentIntroSplit());
        $this->assertSame('More about the homes in Palatine', $area->fresh()->introFolds()['folds'][0]['heading']);
    }

    public function test_every_page_of_the_town_folds_its_story_and_a_service_page_reads_it_through_its_own_trade(): void
    {
        $area = $this->palatine();
        $area->forceFill(['intro_folds' => [
            'lead' => 'Palatine grew in rings from an 1855 core to postwar subdivisions.',
            'history' => "The village's core was platted in 1855 around the Metra station.",
            'potential' => 'Kitchen walls come down for open-concept living; exposed engineered joists can trigger a sprinkler requirement.',
            'source_hash' => AreaServed::introHash($area->local_intro),
        ]])->save();

        // No generated bathroom copy for the pair yet: the shared town block, folds and all.
        $page = $this->get('/areas-served/palatine/services/bathroom-remodeling')->assertOk();
        $page->assertSeeInOrder(['How Palatine was built', 'What that means for remodeling in Palatine', 'building codes for bathroom remodels']);
        $page->assertSee('exposed engineered joists can trigger a sprinkler requirement');
        $page->assertSee('lg:sticky', false);

        // With its own copy the kitchen page leads with the trade, then folds the
        // town's story under it; its permits fold is the kitchen paragraph.
        $copy = \App\Models\AreaServiceContent::create([
            'area_served_id' => $area->id, 'service' => 'kitchen-remodeling',
            'intro' => 'Kitchens in the Winston Park ranches are boxed off from the family room.',
            'popular_requests' => 'Islands with seating.',
            'permit_notes' => 'A Palatine kitchen permit covers the electrical and plumbing rough-ins.',
            'faq' => [['question' => 'Can a Winston Park kitchen open to the family room?', 'answer' => 'Usually, once the wall is checked for load.']],
            'generated_at' => now(),
        ]);
        $page = $this->get('/areas-served/palatine/services/kitchen-remodeling')->assertOk();
        $page->assertSeeInOrder([
            'Kitchen remodeling in Palatine, IL',
            'Kitchens in the Winston Park ranches are boxed off',
            'About Palatine',
            'Palatine grew in rings from an 1855 core to postwar subdivisions.',
            'How Palatine was built',
            'What that means for remodeling in Palatine', // town-wide until the trade's own fold is written
            'Permits for kitchen remodeling in Palatine',
        ]);
        $page->assertDontSee('Palatine permits &amp; building codes', false);
        $page->assertDontSee('More about our work in Palatine');

        // The trade's own reading of the town replaces the town-wide fold.
        $copy->update(['town_potential' => 'Winston Park ranches put the kitchen against the garage wall, so opening it means a beam and a relocated panel.']);
        $page = $this->get('/areas-served/palatine/services/kitchen-remodeling')->assertOk();
        $page->assertSee('What that means for kitchen remodeling in Palatine');
        $page->assertSee('opening it means a beam and a relocated panel');
        $page->assertDontSee('What that means for remodeling in Palatine');
        $page->assertDontSee('exposed engineered joists can trigger a sprinkler requirement');
        // The town page keeps the town-wide fold.
        $this->get('/areas-served/palatine')->assertOk()->assertSee('What that means for remodeling in Palatine')->assertDontSee('relocated panel');
    }

    public function test_the_potential_command_fills_only_the_pairs_missing_their_fold(): void
    {
        $area = $this->palatine();
        $row = \App\Models\AreaServiceContent::create(['area_served_id' => $area->id, 'service' => 'kitchen-remodeling', 'intro' => 'Kitchens here.', 'generated_at' => now()]);
        \App\Models\AreaServiceContent::create(['area_served_id' => $area->id, 'service' => 'bathroom-remodeling', 'intro' => 'Baths here.', 'town_potential' => 'Already written.', 'generated_at' => now()]);
        $this->mock(AiContentService::class, function ($mock) {
            $mock->shouldReceive('generateAreaServicePotential')->once()->with(\Mockery::type(AreaServed::class), 'kitchen-remodeling')
                ->andReturn('Winston Park ranches put the kitchen against the garage wall, so opening it means a beam and a relocated panel, and the 1960s supply lines usually go at the same time.');
            $mock->shouldReceive('getLastError')->andReturn(null);
        });

        $this->artisan('seo:generate-area-service-potential', ['--slug' => 'palatine', '--yes' => true])->assertSuccessful();
        $this->assertStringStartsWith('Winston Park ranches', $row->fresh()->town_potential);
        $this->artisan('seo:generate-area-service-potential', ['--slug' => 'palatine', '--yes' => true])->expectsOutputToContain('Nothing to do')->assertSuccessful();
    }

    public function test_the_split_command_stores_the_models_answer_with_a_fingerprint_and_skips_current_towns(): void
    {
        $area = $this->palatine();
        $this->mock(AiContentService::class, function ($mock) {
            $mock->shouldReceive('splitAreaIntro')->twice()->andReturn([
                'lead' => 'Palatine grew in rings from an 1855 core to postwar subdivisions.',
                'history' => 'The core was platted in 1855; 1920s subdivisions and the postwar boom followed.',
                'potential' => 'Kitchen walls come down; exposed joists can trigger a sprinkler requirement.',
            ]);
            $mock->shouldReceive('getLastError')->andReturn(null);
        });

        $this->artisan('seo:split-area-intros', ['--slug' => 'palatine', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($area->fresh()->intro_folds, 'dry-run saves nothing');

        $this->artisan('seo:split-area-intros', ['--slug' => 'palatine', '--yes' => true])->assertSuccessful();
        $fresh = $area->fresh();
        $this->assertSame(AreaServed::introHash($fresh->local_intro), $fresh->intro_folds['source_hash']);
        $this->assertSame('How Palatine was built', $fresh->introFolds()['folds'][0]['heading']);

        // Current split: the nightly run makes no call for this town.
        $this->artisan('seo:split-area-intros', ['--slug' => 'palatine', '--yes' => true])
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }
}
