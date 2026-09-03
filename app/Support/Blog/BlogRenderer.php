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

    $markdown = preg_replace_callback('/^\s*\[(cover|before-after|timelapse|gallery)\]\s*$/m', function ($m) use (&$placeholders, &$used, $project) {
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

        // Pull photos: float one image beside every second paragraph.
        if ($project) {
            $pool = $project->images->reject(fn ($i) => in_array($i->id, $used, true))->values();
            $side = 'right';
            $n = 0;
            $html = preg_replace_callback('#<p>(?!\s*@@)#', function ($m) use (&$pool, &$side, &$n, &$used, $project) {
                $n++;
                if ($n % 2 === 0 && $pool->isNotEmpty()) {
                    $img = $pool->shift();
                    $used[] = $img->id;
                    $fig = view('blog.media.pull', ['project' => $project, 'image' => $img, 'side' => $side, 'index' => self::lightboxIndex($project, $img)])->render();
                    $side = $side === 'right' ? 'left' : 'right';

                    return $fig . '<p>';
                }

                return '<p>';
            }, $html);

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
            preg_match_all('/^##\s.*$/m', $md, $m, PREG_OFFSET_CAPTURE);
            if (isset($m[0][$nthHeading])) {
                $pos = $m[0][$nthHeading][1];

                return substr($md, 0, $pos) . "[{$tag}]\n\n" . substr($md, $pos);
            }

            return rtrim($md) . "\n\n[{$tag}]\n";
        };

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

        return $project->images->map(fn ($img) => [
            'id' => $img->id,
            'url' => $img->getThumbnailUrl('large'),
            'webpUrl' => $img->getWebpThumbnailUrl('large'),
            'originalUrl' => $img->url,
            'alt' => $img->seo_alt_text ?: $img->alt_text,
            'caption' => $img->caption,
            'pageUrl' => route('projects.image', ['project' => $project, 'image' => $img->slug ?: $img->id]),
        ])->values()->all();
    }
}
