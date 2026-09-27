<?php

namespace Tests\Feature\Citations;

use App\Models\Citation;
use App\Models\Site;
use App\Support\Citations\SiteCitationLinkStore;
use App\Support\Citations\SiteLinkTarget;
use App\Support\Citations\SitePendingCitationRepository;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Citations\Contracts\Mailbox;
use SsSystems\Platform\Citations\LinkCheckRunner;
use SsSystems\Platform\Citations\VerificationInbox;
use Tests\TestCase;

/**
 * Two-Site isolation guard for kit 0.13.0's citations port (RULES.md's
 * tenancy guard: every unit that touches a gsc-scoped model gets this
 * test BEFORE the port ships).
 *
 * `VerificationInboxIsolationTest` half of this file is THE regression
 * test for the live bug this port fixes: gsc's own former
 * `App\Services\Citations\VerificationInbox::run()` queried
 * `Citation::query()->where('status', ...PENDING_VERIFICATION)` with NO
 * `site_id` filter — the only query in that whole class missing the
 * scope every other citations query on this site carries. Two tenants
 * with a citation at the SAME sender domain proves the sweep run for one
 * tenant can never verify (or even count) the other tenant's row.
 */
class CitationsInboxLinksIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_link_check_store_never_reads_or_writes_the_other_tenants_row(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        $ours = Citation::create(['site_id' => $gsc->id, 'slug' => 'shared-directory', 'name' => 'GS Co', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://gs.construction', 'status' => Citation::STATUS_SUBMITTED, 'listing_url' => 'https://directory.test/gs']);
        $theirs = Citation::create(['site_id' => $jp->id, 'slug' => 'shared-directory', 'name' => 'JP Co', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://jpeterson-design.com', 'status' => Citation::STATUS_SUBMITTED, 'listing_url' => 'https://directory.test/jp']);

        Http::fake(['directory.test/*' => Http::response('<html><a href="https://gs.construction/">GS Construction & Remodeling</a></html>', 200)]);

        $rows = Tenancy::for($gsc, function () {
            return (new SiteCitationLinkStore)->withListingUrl();
        });
        $this->assertSame(['shared-directory'], array_column($rows, 'slug'));
        $this->assertSame('https://directory.test/gs', $rows[0]['listing_url'], 'gsc reads only its own row, never jpeterson\'s');

        Tenancy::for($gsc, function () {
            (new LinkCheckRunner(new SiteCitationLinkStore, new SiteLinkTarget))->run();
        });

        $this->assertSame(Citation::STATUS_LIVE, $ours->fresh()->status, 'gsc\'s own row is promoted');
        $this->assertSame(Citation::STATUS_SUBMITTED, $theirs->fresh()->status, 'jpeterson\'s same-slug row is untouched by gsc\'s sweep');
        $this->assertNull($theirs->fresh()->links_to_us);
    }

    public function test_the_pending_citation_repository_never_verifies_the_other_tenants_row(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = Site::where('slug', 'jpeterson')->firstOrFail();

        // Same sender domain on both tenants' rows: before this port,
        // VerificationInbox::run()'s unscoped `pending()` query could match
        // either tenant's citation to an inbox message read for the other.
        $ours = Citation::create(['site_id' => $gsc->id, 'slug' => 'shared-directory', 'name' => 'GS Co', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://shared-directory.test', 'status' => Citation::STATUS_PENDING_VERIFICATION, 'verification' => ['messages_seen' => []]]);
        $theirs = Citation::create(['site_id' => $jp->id, 'slug' => 'shared-directory', 'name' => 'JP Co', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://shared-directory.test', 'status' => Citation::STATUS_PENDING_VERIFICATION, 'verification' => ['messages_seen' => []]]);

        Http::fake(['shared-directory.test/*' => Http::response('Thanks, verified.', 200)]);
        $fakeMailbox = new class implements Mailbox
        {
            public function mode(): ?string
            {
                return 'graph';
            }

            public function messagesSince(\DateTimeInterface $since): array
            {
                return [['id' => 'msg-1', 'subject' => 'Please confirm your email', 'from_domain' => 'mail.shared-directory.test', 'body' => '<a href="https://shared-directory.test/verify?token=abc">Confirm</a>']];
            }
        };

        Tenancy::for($gsc, function () use ($fakeMailbox) {
            $repo = new SitePendingCitationRepository;
            (new VerificationInbox($fakeMailbox, $repo))->run();
        });

        $this->assertSame(Citation::STATUS_SUBMITTED, $ours->fresh()->status, 'gsc\'s own pending row is verified');
        $this->assertSame(Citation::STATUS_PENDING_VERIFICATION, $theirs->fresh()->status, 'jpeterson\'s same-domain row is untouched — this is the live-bug regression guard');
        $this->assertSame([], $theirs->fresh()->verification['messages_seen'] ?? [], 'jpeterson\'s row never even sees the message id');
    }
}
