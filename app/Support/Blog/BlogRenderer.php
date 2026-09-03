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
