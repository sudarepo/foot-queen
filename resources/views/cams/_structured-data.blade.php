@php
    $structuredDataItems = $cams->take(20)->values()->map(function ($cam, $index) {
        return array_filter([
            '@type'   => 'ListItem',
            'position' => $index + 1,
            'name'    => $cam->username,
            'image'   => $cam->thumbnail_url,
        ]);
    });
@endphp

@if ($structuredDataItems->isNotEmpty())
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $h1 ?? 'Live Cams',
            'numberOfItems' => $structuredDataItems->count(),
            'itemListElement' => $structuredDataItems,
        ], JSON_UNESCAPED_SLASHES) !!}
    </script>
@endif
