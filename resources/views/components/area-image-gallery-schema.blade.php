@props(['area' => null, 'limit' => 12])

@php
    $gallery = null;

    if ($area) {
        /** Pull project images for this city — uses the same matching logic as project-schema. */
        $cityRaw = $area->city;
        $images = \App\Models\ProjectImage::query()
            ->whereHas('project', function ($q) use ($cityRaw) {
                $q->where('is_published', true)
                  ->where(function ($q2) use ($cityRaw) {
                      $q2->where('location', $cityRaw)
                         ->orWhere('location', 'LIKE', $cityRaw.'%');
                  });
            })
            ->whereNotNull('alt_text')
            ->orderByDesc('is_cover')
            ->orderBy('sort_order')
            ->limit((int) $limit)
            ->get(['id','project_id','path','disk','thumbnails','alt_text','seo_alt_text','caption','width','height','is_cover']);

        if ($images->isNotEmpty()) {
            $geo = (! empty($area->latitude) && ! empty($area->longitude))
                ? [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $area->latitude,
                'longitude' => (float) $area->longitude,
              ]
                : null;

            $contentLocation = [
                '@type'   => 'Place',
                'name'    => $area->city.', IL',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $area->city,
                    'addressRegion'   => 'IL',
                    'addressCountry'  => 'US',
                ],
            ];
            if ($geo) {
                $contentLocation['geo'] = $geo;
            }

            $galleryImages = $images->map(function ($img, $i) use ($contentLocation) {
                $imageUrl = $img->getAnyUrl('large');
                if (! is_string($imageUrl) || trim($imageUrl) === '') {
                    return null;
                }

                return [
                    '@type'           => 'ImageObject',
                    'url'             => $imageUrl,
                    'contentUrl'      => $imageUrl,
                    'name'            => $img->seo_alt_text ?: $img->alt_text,
                    'description'     => $img->caption ?: ($img->seo_alt_text ?: $img->alt_text),
                    'caption'         => $img->caption ?: ($img->seo_alt_text ?: $img->alt_text),
                    'width'           => $img->width ?? 1200,
                    'height'          => $img->height ?? 800,
                    'position'        => $i + 1,
                    'representativeOfPage' => (bool) ($img->is_cover ?? false),
                    'creditText'      => 'GS Construction',
                    'creator'         => ['@id' => 'https://gs.construction/#organization'],
                    'copyrightNotice' => '© '.now()->year.' GS Construction',
                    'license'         => 'https://gs.construction/',
                    'acquireLicensePage' => url('/contact'),
                    'contentLocation' => $contentLocation,
                ];
            })->filter()->values()->all();

            if (! empty($galleryImages)) {
                $gallery = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'ImageGallery',
                    '@id'         => url('/areas-served/'.$area->slug).'#gallery',
                    'name'        => 'Remodeling Projects in '.$area->city.', IL',
                    'description' => 'Recent kitchen, bathroom, and home remodeling projects completed by GS Construction in '.$area->city.', Illinois.',
                    'url'         => url('/areas-served/'.$area->slug),
                    'inLanguage'  => 'en-US',
                    'isPartOf'    => ['@id' => 'https://gs.construction/#website'],
                    'about'       => ['@id' => url('/areas-served/'.$area->slug).'#localbusiness'],
                    'numberOfItems' => count($galleryImages),
                    'image'       => $galleryImages,
                ];
            }
        }
    }
@endphp

@if($gallery)
<script type="application/ld+json">
{!! json_encode($gallery, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
