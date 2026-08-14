<?php

namespace App\Support;

use App\Models\ProjectSlugHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Maps a retired project URL onto its current one.
 *
 * Project slugs carry the town for search ("/projects/first-floor-remodel-2"
 * became "/projects/first-floor-remodel-palatine-il"). Photo pages nest under
 * the project slug, so one rename moves that project's whole photo set — the
 * larger half of the indexed surface. Without a 301 the rename costs exactly
 * the ranking it was meant to gain.
 *
 * Called from the exception render hook rather than middleware: route-model
 * binding throws NotFoundHttpException from inside SubstituteBindings, which
 * is raised before an appended web-group middleware's frame is reached, so
 * such a middleware never observes the 404.
 */
class RenamedProjectRedirector
{
    public static function for(Request $request): ?RedirectResponse
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'projects/')) {
            return null;
        }

        $segments = explode('/', $path);
        $oldSlug = $segments[1] ?? null;
        if (! $oldSlug) {
            return null;
        }

        $project = ProjectSlugHistory::with('project')->where('slug', $oldSlug)->first()?->project;
        if (! $project) {
            return null;
        }

        // A stale row pointing at the slug the project now uses would redirect
        // the URL to itself forever.
        if ($project->slug === $oldSlug) {
            return null;
        }

        // Rebuild the SAME path with the new slug so /photos/{image} — and any
        // nested route added later — survives the rename untouched.
        $segments[1] = $project->slug;

        // …but only if the rebuilt target actually resolves. Photo slugs were
        // re-slugged independently of project renames (some gained -1/-3
        // suffixes) with no history table, so a rebuilt /photos/{old-image}
        // can be a dead path: Search Console then counts the same URL once in
        // "Page with redirect" and again in "Not found (404)" — a 301 chain
        // into a wall (16 paths, 538 hits). When the deep path is gone, send
        // the visitor to the project page, which is where the photo lives now.
        if (isset($segments[2]) && $segments[2] === 'photos' && isset($segments[3])) {
            $imageExists = $project->images()
                ->where('slug', $segments[3])
                ->exists();
            if (! $imageExists) {
                return new RedirectResponse(url("projects/{$project->slug}"), 301);
            }
        }

        $target = url(implode('/', $segments));

        if ($query = $request->getQueryString()) {
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, 301);
    }
}
