<?php

namespace App\Support\Blog;

use App\Models\BlogPost;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown → HTML for blog bodies, with the media shortcodes expanded from the
 * linked project's real files. Shortcodes live on their own line:
 *   [cover] [before-after] [timelapse] [gallery]
 * Unknown/unbackable shortcodes render as nothing, never as literal text.
 */
class BlogRenderer
{
    public static function render(BlogPost $post): string
    {
        $project = $post->project?->loadMissing(['images', 'beforeAfters', 'timelapses.frames']);

        $markdown = (string) $post->body;
        $placeholders = [];

        $markdown = preg_replace_callback('/^\s*\[(cover|before-after|timelapse|gallery)\]\s*$/m', function ($m) use (&$placeholders, $project) {
            $html = $project ? match ($m[1]) {
                'cover' => view('blog.media.cover', ['project' => $project])->render(),
                'before-after' => view('blog.media.before-after', ['project' => $project])->render(),
                'timelapse' => view('blog.media.timelapse', ['project' => $project])->render(),
                'gallery' => view('blog.media.gallery', ['project' => $project])->render(),
            } : '';
            $key = '@@MEDIA' . count($placeholders) . '@@';
            $placeholders[$key] = $html;

            return "\n\n{$key}\n\n";
        }, $markdown);

        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = (string) $converter->convert($markdown);

        // CommonMark wraps the placeholder in <p>; swap the whole paragraph.
        foreach ($placeholders as $key => $media) {
            $html = preg_replace('#<p>\s*' . preg_quote($key, '#') . '\s*</p>#', $media, $html, 1);
            $html = str_replace($key, $media, $html);
        }

        return $html;
    }
}
