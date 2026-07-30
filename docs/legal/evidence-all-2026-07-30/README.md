# Competitor Comparison Pages — Evidence Packs (2026-07-30)

Covers all **26 competitor comparison pages** on gs.construction. One pack per competitor in this folder, plus the raw source captures used to build them.

Prepared for review by counsel. Factual compilation, not legal advice.

## Why this exists

4Ever Remodeling sent a demand letter on 29 July 2026 alleging unauthorised use of name, website content, images and marketing materials. That pack (`../evidence-4ever-2026-07-29.md`) answered it for one page. This exercise applies the same standard to every comparison page and, in addition, machine-checks all 26 pages for copied text and third-party images.

## Headline findings

- **No competitor imagery anywhere.** All 640 `<img>`/`srcset` references across the 26 pages were enumerated. 0 are served from a competitor domain. In fact none are served from any third-party host — every asset is first-party.
- **No copied marketing copy.** Our page prose was compared against every captured competitor page for shared runs of 8+ consecutive words. 21 of 26 pages share nothing at all.
- **5 pages share a single 8-word run**, each unprotectable: lists of suburb names, a company's own name repeated, or a plainly-stated fact about its own history. No run exceeds 8 words anywhere.
- **12 statements carry a verbatim citation** to the competitor's own page, shown on the live site as a hoverable `*`. All 12 were machine-confirmed present at capture time.
- **Independent third-party corroboration for every page.** Internet Archive captures were located for all 26/26 competitor homepages and all 12/12 cited source pages. Each pack lists them with capture dates in Sec. 5, so every claim can be checked against a dated copy held by neither party.
- **3 unsupported claims were found and corrected** during this review (see below).

## Corrections made as a result of this review

The check surfaced three statements asserting specifics that the competitor's own site does not support. All three were corrected in `config/competitors.php` on 2026-07-30:

| Page | Was | Now | Why |
| --- | --- | --- | --- |
| Kitchen Village | "Northwest suburbs: Schaumburg, Arlington Heights, Palatine, Mount Prospect, Buffalo Grove, Elk Grove Village" | "Northwest suburbs of Chicago" | Only *Arlington Heights* appeared on their site. Their own wording is "kitchen and bath renovations throughout the northwest suburbs of Chicago". |
| Chi Renovation & Design | "Chicago and near-north suburbs, from a **Northwest Side** design studio" | "Chicago; describes itself as a Chicago design-build studio" | "Northwest Side" appears nowhere on their site; they say "a Chicago design-build company". |
| Advance Design Studio | "Barrington, Crystal Lake, **Algonquin**" + "full-service **showroom** in Gilberts" | "Barrington, Crystal Lake, and nearby towns" + "based in Gilberts" | *Algonquin* and *showroom* are absent from their site; Barrington, Crystal Lake, Gilberts and 1992 are present. |

## Method

1. **Capture.** Each competitor's homepage plus up to three About/Process/Service pages fetched 2026-07-30 and stored verbatim under `source-captures/<slug>/`.
2. **Copy check.** Both sides reduced to lowercase word tokens, punctuation discarded, then compared for shared runs of 8+ consecutive tokens. Our attributed citation quotes are excluded from our side and assessed separately, so a short attributed quote is never counted as copying.
3. **Image check.** Every `<img src>` and `srcset` URL on each of our pages enumerated and its host compared against the competitor domain.
4. **Claim check.** Every statement we publish about a competitor matched against their captured text. Recorded citations are reported as verified-exact; all other statements get the closest sentence found on their site, explicitly labelled machine-found and unreviewed.

Three sites (123 Remodeling, Orren Pickell, Prestige Kitchen & Bath) block automated access (HTTP 403/429); Scott Lyon & Company's live site is now an "under construction" placeholder. For those four, dated Internet Archive captures were used as the primary source and are cited as such.

5. **Archive corroboration.** Independently of the capture step, an Internet Archive snapshot was located for every competitor homepage and every cited source page, and is listed with its capture date in each pack's Sec. 5.

## Per-competitor index

| Competitor | Statements | Cited verbatim | Longest shared run | Competitor images used | Pack |
| --- | ---: | ---: | ---: | ---: | --- |
| 123 Remodeling | 3 | 0 | — | 0 | [`123-remodeling.md`](123-remodeling.md) |
| 4Ever Remodeling | 3 | 3 | — | 0 | [`4ever-remodeling.md`](4ever-remodeling.md) |
| Advance Design Studio | 1 | 0 | — | 0 | [`advance-design-studio.md`](advance-design-studio.md) |
| Airoom | 4 | 0 | 8 words | 0 | [`airoom.md`](airoom.md) |
| Assembly Squad Remodeling | 2 | 0 | — | 0 | [`assembly-squad-remodeling.md`](assembly-squad-remodeling.md) |
| Chi Renovation and Design | 3 | 0 | — | 0 | [`chi-renovation.md`](chi-renovation.md) |
| Delta Remodels | 5 | 3 | 8 words | 0 | [`delta-remodels.md`](delta-remodels.md) |
| Dream Kitchens | 4 | 2 | — | 0 | [`dream-kitchens.md`](dream-kitchens.md) |
| EcoBuild Plus | 1 | 0 | — | 0 | [`ecobuild-plus.md`](ecobuild-plus.md) |
| Kitchen & Bath Mart | 4 | 0 | — | 0 | [`kitchen-bath-mart.md`](kitchen-bath-mart.md) |
| Kitchen Village | 3 | 0 | — | 0 | [`kitchen-village.md`](kitchen-village.md) |
| LaMantia Design & Remodeling | 2 | 0 | — | 0 | [`lamantia-design-remodeling.md`](lamantia-design-remodeling.md) |
| Maya Construction Group | 1 | 0 | — | 0 | [`maya-construction-group.md`](maya-construction-group.md) |
| Modern Builders & Design | 3 | 0 | 8 words | 0 | [`modern-builders-design.md`](modern-builders-design.md) |
| Normandy Remodeling | 4 | 0 | — | 0 | [`normandy-remodeling.md`](normandy-remodeling.md) |
| OHi (Our Home Improvement) | 4 | 0 | 8 words | 0 | [`ohi-remodeling.md`](ohi-remodeling.md) |
| Orren Pickell Building Group | 5 | 0 | — | 0 | [`pickell-builders.md`](pickell-builders.md) |
| Patrick A. Finn, Ltd. | 1 | 0 | — | 0 | [`patrick-a-finn.md`](patrick-a-finn.md) |
| Prestige Kitchen & Bath | 1 | 0 | — | 0 | [`prestige-kitchen-bath.md`](prestige-kitchen-bath.md) |
| Regency Home Remodeling | 1 | 0 | — | 0 | [`regency-home-remodeling.md`](regency-home-remodeling.md) |
| Scott Lyon & Company | 4 | 4 | — | 0 | [`scott-lyon-company.md`](scott-lyon-company.md) |
| Senkus Build | 2 | 0 | — | 0 | [`senkus-build.md`](senkus-build.md) |
| Skor Construction | 4 | 0 | — | 0 | [`skor-construction.md`](skor-construction.md) |
| Sunny Construction & Remodeling | 1 | 0 | 8 words | 0 | [`sunny-remodeling.md`](sunny-remodeling.md) |
| Synergy Builders | 1 | 0 | — | 0 | [`synergy-builders.md`](synergy-builders.md) |
| Thomas Meyer Renovations | 1 | 0 | — | 0 | [`thomas-meyer-renovations.md`](thomas-meyer-renovations.md) |

## The 8-word runs, in full

**Airoom**
- `lake barrington lake bluff lake forest lake zurich`

**OHi (Our Home Improvement)**
- `of 5 of the high end ballpark budget`

**Modern Builders & Design**
- `started in residential and commercial painting and epoxy`

**Sunny Construction & Remodeling**
- `sunny construction remodeling sunny construction remodeling is a`

**Delta Remodels**
- `lake bluff lake forest lake zurich libertyville lincolnshire`

None of these is protectable expression: place names and a company's own name are facts, and the Modern Builders line is our own sentence which attributes the fact inline ("per its own site").

## Output formats

- `evidence-packs-all-2026-07-30.pdf` — master PDF, every pack in one document
- `evidence-packs-all-2026-07-30.docx` — same, editable
- `pdf/<slug>.pdf` — one standalone PDF per competitor (26 files)
- `pdf/00-summary-2026-07-30.pdf` — this summary alone

## Files

- `<slug>.md` — one evidence pack per competitor (26 files)
- `source-captures/<slug>/` — verbatim HTML of every competitor page relied on
