<?php

namespace App\Support\Blog;

use App\Models\BlogPost;
use App\Models\Project;
use App\Models\ProjectImage;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown → HTML for blog bodies, with the project's real media woven in.
 *
 * Two sources of images on the page:
 *  1. Shortcodes the writer placed ([cover] [before-after] [timelapse]
 *     [gallery]) — expanded from the linked project's files.
 *  2. Magazine-style "pull" photos the renderer inserts itself: every second
 *     paragraph gets a project photo floated left/right so text wraps around
 *     it. The writer never sees these; they come from whatever photos the
 *     shortcodes didn't use, in sort order.
 *
 * Every image is a lightbox trigger: the page wraps the article in one Alpine
 * scope holding ALL project images (see blog-show.blade.php), and each figure
 * calls open(<index into that array>). Indices come from lightboxIndex().
 */
class BlogRenderer
{
    public static function render(BlogPost $post): string
    {
        $project = $post->project?->loadMissing(['images', 'beforeAfters', 'timelapses.frames']);
        $markdown = (string) $post->body;
        $placeholders = [];
        $used = []; // image ids already shown by cover/gallery, so pull-photos don't repeat them

        if ($project) {
        $markdown = self::ensureMediaShortcodes($markdown, $project);
    }

    $markdown = preg_replace_callback('/^\s*\[(before|cover|before-after|timelapse|gallery)\]\s*$/m', function ($m) use (&$placeholders, &$used, $project) {
            $html = '';
            if ($project) {
                if ($m[1] === 'cover' && ($cover = $project->cover())) {
                    $used[] = $cover->id;
                }
                if ($m[1] === 'gallery') {
                    // Gallery shows everything not used elsewhere — resolved after pull photos, see below.
                    $html = '@@GALLERY@@';
                } else {
                    $html = view('blog.media.' . $m[1], ['project' => $project])->render();
                }
            }
            $key = '@@MEDIA' . count($placeholders) . '@@';
            $placeholders[$key] = $html;

            return "\n\n{$key}\n\n";
        }, $markdown);

        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = (string) $converter->convert($markdown);

        // Photos beside the text. Not floats: a float lets a second figure
        // stack against the first and drops a paragraph's last line under the
        // picture once the float ends. Each photo instead gets an explicit
        // row — a text column holding one or two paragraphs and the photo —
        // so nothing overlaps and no line is ever stranded.
        if ($project) {
            $pool = $project->images->reject(fn ($i) => in_array($i->id, $used, true))->values();
            // Cadence and starting side vary per project, so two posts read
            // side by side do not share one rhythm.
            mt_srand((int) $project->id * 31 + 5);
            $every = mt_rand(1, 2);
            $side = mt_rand(0, 1) ? 'right' : 'left';
            mt_srand();

            $beforeKey = array_search(true, array_map(fn ($h) => str_contains($h, 'aria-label="Open the before photo"'), $placeholders), true) ?: null;

            $html = self::layout($html, $placeholders, function (array $paragraphs) use (&$pool, &$side, &$used, $project) {
                if ($pool->isEmpty()) {
                    return null;
                }
                $img = $pool->shift();
                $used[] = $img->id;
                $fig = view('blog.media.pull', ['project' => $project, 'image' => $img, 'index' => self::lightboxIndex($project, $img)])->render();
                $row = self::row($fig, $paragraphs, $side);
                $side = $side === 'right' ? 'left' : 'right';

                return $row;
            }, $every, $beforeKey);

            foreach ($placeholders as $key => $media) {
                if ($media === '@@GALLERY@@') {
                    $rest = $project->images->reject(fn ($i) => in_array($i->id, $used, true))->values();
                    $placeholders[$key] = view('blog.media.gallery', ['project' => $project, 'images' => $rest])->render();
                }
            }
        }

        foreach ($placeholders as $key => $media) {
            $html = preg_replace('#<p>\s*' . preg_quote($key, '#') . '\s*</p>#', $media, $html, 1);
            $html = str_replace($key, $media, $html);
        }

        return $html;
    }

    /**
     * Walk the converted HTML block by block and lay photos beside text.
     *
     * - The Before placeholder becomes a row with the one or two paragraphs
     *   that follow it (the paragraph describing the space as we found it).
     * - After \$every plain paragraphs, the next paragraph gets a pull-photo
     *   row with itself and the paragraph after it. The count restarts after
     *   any media block or row, so the paragraph right after a photo is
     *   always plain text — photos never sit directly on top of each other.
     *
     * @param  array<string, string>  $placeholders
     * @param  callable(array<int, string>): ?string  $pull  builds a row for the given paragraphs, or null when out of photos
     */
    protected static function layout(string $html, array $placeholders, callable $pull, int $every, ?string $beforeKey): string
    {
        preg_match_all('#<(p|h[1-6]|ul|ol|blockquote|pre|table)\b[^>]*>.*?</\1>|<hr\s*/?>#s', $html, $m);
        $blocks = $m[0];
        $isPara = fn (?string $b) => $b !== null && str_starts_with($b, '<p') && ! preg_match('#^<p>\s*@@MEDIA\d+@@\s*</p>$#', $b);
        $isMedia = fn (?string $b) => $b !== null && (bool) preg_match('#^<p>\s*@@MEDIA\d+@@\s*</p>$#', $b);
        $mediaKey = fn (string $b) => preg_match('#(@@MEDIA\d+@@)#', $b, $k) ? $k[1] : null;

        $out = [];
        $since = 0; // plain paragraphs emitted since the last photo or media block
        for ($i = 0; $i < count($blocks); $i++) {
            $b = $blocks[$i];

            if ($isMedia($b) && $beforeKey && $mediaKey($b) === $beforeKey) {
                $paras = [];
                while (count($paras) < 2 && $isPara($blocks[$i + 1] ?? null)) {
                    $paras[] = $blocks[++$i];
                }
                $out[] = self::row($placeholders[$beforeKey], $paras, 'right');
                $since = 0;

                continue;
            }

            if (! $isPara($b)) {
                $out[] = $b;
                if ($isMedia($b)) {
                    $since = 0;
                }

                continue;
            }

            $next = $blocks[$i + 1] ?? null;
            if ($since >= $every) {
                $paras = [$b];
                if ($isPara($next)) {
                    $paras[] = $next;
                }
                $row = $pull($paras);
                if ($row !== null) {
                    $out[] = $row;
                    $i += count($paras) - 1;
                    $since = 0;

                    continue;
                }
            }

            $out[] = $b;
            $since++;
        }

        return implode("\n", $out);
    }

    /** A text column and a photo, side by side from the sm breakpoint; stacked below it. */
    protected static function row(string $figure, array $paragraphs, string $side): string
    {
        $text = '<div class="min-w-0 flex-1">' . implode("\n", $paragraphs) . '</div>';
        $fig = '<div class="w-full shrink-0 sm:w-[46%]">' . $figure . '</div>';

        return '<div class="my-6 sm:flex sm:items-start sm:gap-8">' . ($side === 'left' ? $fig . $text : $text . $fig) . '</div>';
    }

    /** Position of an image inside the page-level lightbox array (all project images, sort order). */
    /**
     * The review to show with a post, with what the shared review card needs:
     * the newest visible testimonial linked to the project, and an avatar
     * picked the way /reviews/* picks it (a seeded non-cover project photo).
     *
     * @return array{testimonial: \App\Models\Testimonial, paragraphs: array, imageUrl: string, areaSlug: ?string}|null
     */
    public static function review(?Project $project): ?array
    {
        if (! $project) {
            return null;
        }

        $testimonial = $project->testimonials->where('is_hidden', false)->sortByDesc('review_date')->first();
        if (! $testimonial) {
            return null;
        }

        $coverId = $project->cover()?->id;
        $avatar = \App\Support\SeededRandom::order(
            ProjectImage::query()->where('project_id', $project->id)->when($coverId, fn ($q) => $q->where('id', '!=', $coverId)),
            (int) $testimonial->getKey() + 7919,
        )->first() ?: $project->cover();

        $imageUrl = $avatar
            ? ($avatar->getWebpThumbnailUrl('medium') ?? $avatar->getThumbnailUrl('medium') ?? $avatar->url)
            : asset('images/greg-patryk-thumb.jpg');

        return [
            'testimonial' => $testimonial,
            'paragraphs' => $testimonial->paragraphs(),
            'imageUrl' => $imageUrl,
            'areaSlug' => $testimonial->areaSlug(),
        ];
    }

    /**
     * A project's "before" — its before/after pairs and its timelapses — is
     * shown whether or not the writer placed the shortcode. A missing
     * [before-after] goes in front of the second heading, a missing
     * [timelapse] in front of the third; with fewer headings they go at the end.
     */
    public static function ensureMediaShortcodes(string $markdown, Project $project): string
    {
        $has = fn (string $tag) => (bool) preg_match('/^\s*\[' . preg_quote($tag, '/') . '\]\s*$/m', $markdown);
        $insert = function (string $md, string $tag, int $nthHeading): string {
            preg_match_all('/^#{2,3}\s.*$/m', $md, $m, PREG_OFFSET_CAPTURE);
            if (isset($m[0][$nthHeading])) {
                $pos = $m[0][$nthHeading][1];

                return substr($md, 0, $pos) . "[{$tag}]\n\n" . substr($md, $pos);
            }

            return rtrim($md) . "\n\n[{$tag}]\n";
        };

        // The "before" sits in the first section, beside the paragraph that
        // describes the space as we found it: right after the cover when the
        // writer placed one, otherwise after the opening paragraph.
        if (self::beforeImage($project) && ! $has('before')) {
            if (preg_match('/^[ \t]*\[cover\][ \t]*$/m', $markdown, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $markdown = substr($markdown, 0, $pos) . "\n\n[before]" . substr($markdown, $pos);
            } elseif (preg_match('/\n\s*\n/', $markdown, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $markdown = substr($markdown, 0, $pos) . "\n\n[before]" . substr($markdown, $pos);
            } else {
                $markdown = rtrim($markdown) . "\n\n[before]\n";
            }
        }
        if ($project->beforeAfters->isNotEmpty() && ! $has('before-after')) {
            $markdown = $insert($markdown, 'before-after', 1);
        }
        if ($project->timelapses->contains(fn ($t) => $t->frames->count() >= 2) && ! $has('timelapse')) {
            $markdown = $insert($markdown, 'timelapse', 2);
        }

        return $markdown;
    }

    public static function lightboxIndex(Project $project, ProjectImage $image): int
    {
        return (int) $project->images->search(fn ($i) => $i->id === $image->id);
    }

    /** The array the page's Alpine lightbox holds — same shape the project gallery uses. */
    public static function lightboxImages(?Project $project): array
    {
        if (! $project) {
            return [];
        }

        $images = $project->images->map(fn ($img) => [
            'id' => $img->id,
            'url' => $img->getThumbnailUrl('large'),
            'webpUrl' => $img->getWebpThumbnailUrl('large'),
            'originalUrl' => $img->url,
            'alt' => $img->seo_alt_text ?: $img->alt_text,
            'caption' => $img->caption,
            'pageUrl' => route('projects.image', ['project' => $project, 'image' => $img->slug ?: $img->id]),
        ])->values()->all();

        // The "before" shot rides along at the end, so the big Before block
        // opens in the same viewer as everything else.
        if ($before = self::beforeImage($project)) {
            $images[] = [
                'id' => 'before',
                'url' => $before['url'],
                'webpUrl' => null,
                'originalUrl' => $before['url'],
                'alt' => $before['alt'],
                'caption' => $before['caption'],
                'pageUrl' => route('projects.show', $project) . '#timelapse',
            ];
        }

        return $images;
    }

    /**
     * The project's "before": the first frame of its first timelapse, or the
     * before half of its first before/after pair. Null when it has neither.
     *
     * @return array{url: string, alt: string, caption: string}|null
     */
    public static function beforeImage(Project $project): ?array
    {
        $type = strtolower(Project::projectTypes()[$project->project_type] ?? 'project');
        $where = $project->location ? " in {$project->location}" : '';

        $timelapse = $project->timelapses->first(fn ($t) => $t->frames->count() >= 2);
        if ($timelapse) {
            $frame = $timelapse->frames->sortBy('sort_order')->first();

            return [
                'url' => $frame->url,
                'alt' => "Before: {$project->title}{$where}, ahead of the {$type}",
                'caption' => 'Before — the space the day we started',
            ];
        }

        $pair = $project->beforeAfters->first();
        if ($pair && $pair->before_url) {
            return [
                'url' => $pair->before_url,
                'alt' => "Before: {$project->title}{$where}, ahead of the {$type}",
                'caption' => 'Before — the space as we found it',
            ];
        }

        return null;
    }

    /** Lightbox index of the before shot: it is appended after the project images. */
    public static function beforeLightboxIndex(Project $project): int
    {
        return $project->images->count();
    }
}
