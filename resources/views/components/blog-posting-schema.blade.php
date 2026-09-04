@blaze(memo: true)
@props(['post'])

@php
    $project = $post->project;
    $cover = $project?->cover();
    $images = $project ? $project->images->take(6)->map(fn ($i) => $i->getAnyUrl('large') ?: $i->url)->filter()->values()->all() : [];
    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        '@id' => $post->url() . '#post',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $post->url()],
        'headline' => \Illuminate\Support\Str::limit($post->title, 110, ''),
        'description' => $post->excerpt ?: $post->meta_description,
        'image' => $images ?: null,
        // Google wants datePublished to be when the page went live and to match
        // the visible date. The story's own date (the project's completion) is
        // shown too and carried on the CreativeWork below, not here.
        'datePublished' => ($post->published_at ?? $post->displayDate())?->toIso8601String(),
        'dateModified' => ($post->updated_at ?? $post->published_at)?->toIso8601String(),
        'author' => ['@id' => 'https://gs.construction/#business'],
        'publisher' => ['@id' => 'https://gs.construction/#business'],
        'inLanguage' => 'en-US',
        'about' => $project ? array_filter([
            '@type' => 'CreativeWork',
            'name' => $project->title,
            'url' => route('projects.show', $project),
            'locationCreated' => $project->location ? ['@type' => 'Place', 'name' => $project->location] : null,
            'dateCreated' => $post->dated_at?->toDateString(),
        ]) : null,
        'keywords' => $project ? array_values(array_filter([
            ($project->project_type ?: 'home') . ' remodel',
            $project->location ? "{$project->location} remodeling" : null,
            'project story',
        ])) : null,
        'wordCount' => str_word_count(strip_tags((string) $post->body)),
    ], fn ($v) => $v !== null && $v !== '' && $v !== []);
@endphp
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
