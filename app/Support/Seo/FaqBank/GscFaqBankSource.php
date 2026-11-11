<?php

namespace App\Support\Seo\FaqBank;

use App\Models\AreaServed;
use App\Support\Seo\BingCoverageUrls;
use App\Support\Seo\CompetitorFilter;
use Illuminate\Support\Facades\Route;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\FaqBankSource;
use SsSystems\Platform\Seo\MapPack\MapPackStore;

/**
 * gs.construction's side of the kit's FAQ bank (kit 0.24.0, 2026-11-10):
 * which of this site's pages `seo:faq-bank` mines, and the few facts that
 * keep every derived answer honest. Bound in AppServiceProvider next to
 * ConfigFaqCatalog, which publishes the bank behind the hand-written
 * answers on /geo/answers.json.
 *
 * The pages are this site's own sitemap URL set — the same list
 * BingCoverageUrls hands the Bing coverage sync, so the bank and the
 * coverage screens count the same pages — labelled by path:
 *
 *   /services/{slug}                 service       /remodeling/{slug}   guide
 *   /costs/{slug}                    cost          /permits/{slug}      permit
 *   /projects/{slug}                 project       /blog/{slug}         blog
 *   /areas-served/{town}             town          /areas-served/{town}/… town_service
 *   everything else                  page
 *
 * and ordered by what a homeowner asks first (KIND_ORDER), the sitemap's
 * own order kept within a kind — the builder visits never-seen pages in
 * this order. Left out on purpose: /faq (its answers are the hand-written
 * list already), /reviews and /reviews/* (customer quotes, not facts of
 * ours to restate), /projects/{slug}/photos/* (one caption each, not a
 * page to mine) and the /projects/{type} gallery filters, which are index
 * pages, not projects (read off the projects.type route so the two never
 * drift).
 *
 * textFor() is null for every page: the builder renders it in-process
 * (the kit's InProcessPageRenderer) and reads `<main>`, so the header,
 * the navbar and the footer never become "facts" an answer may restate.
 */
final class GscFaqBankSource implements FaqBankSource
{
    /** The order the builder visits never-seen pages in. */
    public const KIND_ORDER = ['service', 'guide', 'cost', 'permit', 'page', 'project', 'town', 'town_service', 'blog'];

    public function __construct(
        private readonly BingCoverageUrls $urls,
        private readonly MapPackStore $mapPack,
    ) {}

    public function pages(): array
    {
        $rank = array_flip(self::KIND_ORDER);
        $pages = [];

        foreach ($this->urls->urls() as $url) {
            $kind = self::kindFor($url);

            if ($kind !== null) {
                $pages[] = ['url' => $url, 'kind' => $kind];
            }
        }

        usort($pages, static fn (array $a, array $b): int => $rank[$a['kind']] <=> $rank[$b['kind']]);

        return $pages;
    }

    /** The kind of a URL by its path, or null for a page the bank leaves alone. */
    public static function kindFor(string $url): ?string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn (string $s): bool => $s !== ''));
        $first = $segments[0] ?? '';
        $depth = count($segments);

        return match (true) {
            $first === 'faq', $first === 'reviews' => null,
            $first === 'projects' && $depth > 2 => null,
            $first === 'projects' && $depth === 2 => self::isProjectGalleryFilter($segments[1]) ? 'page' : 'project',
            $first === 'services' && $depth > 1 => 'service',
            $first === 'remodeling' && $depth > 1 => 'guide',
            $first === 'costs' && $depth > 1 => 'cost',
            $first === 'permits' && $depth > 1 => 'permit',
            $first === 'areas-served' && $depth === 2 => 'town',
            $first === 'areas-served' && $depth > 2 => 'town_service',
            $first === 'blog' && $depth > 1 => 'blog',
            default => 'page',
        };
    }

    public function textFor(string $url): ?string
    {
        return null;
    }

    public function businessName(): string
    {
        return (string) config('brand.display_name', config('brand.name', 'GS Construction'));
    }

    public function language(): string
    {
        return 'US English';
    }

    /** The towns on /admin/areas-served — the served towns, this site's own rows only (BelongsToSite). */
    public function towns(): array
    {
        return AreaServed::query()
            ->orderBy('city')
            ->pluck('city')
            ->map(static fn ($city): string => trim((string) $city))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every name an answer must never carry: the /compare companies
     * (config/competitors.php) and their hosts, plus the map-pack leaders
     * seo:map-pack-grid has stored for this site — each name also as the
     * part before a parenthetical and without a legal suffix, since a model
     * writes "OHi" for "OHi (Our Home Improvement)" and "Patrick A. Finn"
     * for "Patrick A. Finn, Ltd.". Never our own name, whatever a stored
     * listing says.
     */
    public function bannedTerms(): array
    {
        $names = [];

        foreach ((array) config('competitors.competitors', []) as $competitor) {
            $names[] = (string) ($competitor['name'] ?? '');
        }

        foreach ($this->mapPack->topCompetitors(50) as $leader) {
            $names[] = (string) ($leader['name'] ?? '');
        }

        return array_values(array_unique([...self::nameVariants($names), ...CompetitorFilter::knownLocalHosts()]));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function nameVariants(array $names): array
    {
        $own = array_values(array_filter(array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            [config('brand.name'), config('brand.display_name'), config('brand.legal_name'), config('brand.also_known_as')],
        )));
        $terms = [];

        foreach ($names as $name) {
            $variants = [trim((string) preg_replace('/\s+/u', ' ', $name))];

            if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $variants[0], $m) === 1) {
                $variants[] = trim($m[1]);
                $variants[] = trim($m[2]);
            }

            foreach ($variants as $variant) {
                $variants[] = trim((string) preg_replace('/,?\s+(?:ltd|inc|llc|co)\.?$/iu', '', $variant));
            }

            foreach ($variants as $variant) {
                $variant = trim($variant);
                $lower = mb_strtolower($variant);

                if (mb_strlen($variant) < 3) {
                    continue;
                }

                foreach ($own as $ours) {
                    if (str_contains($ours, $lower) || str_contains($lower, $ours)) {
                        continue 2;
                    }
                }

                $terms[$lower] = $variant;
            }
        }

        return array_values($terms);
    }

    /** /projects/kitchens, /projects/bathrooms, …: the gallery filters of the projects.type route, not projects. */
    private static function isProjectGalleryFilter(string $slug): bool
    {
        $pattern = Route::getRoutes()->getByName('projects.type')?->wheres['type'] ?? null;

        return is_string($pattern) && $pattern !== '' && preg_match('/^(?:'.$pattern.')$/', $slug) === 1;
    }
}
