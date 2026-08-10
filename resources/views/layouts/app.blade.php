@php
    /**
     * Cache-busted stylesheet URL.
     *
     * app.css is served straight out of /public — there's no Vite build for
     * it, so nothing hashes the filename — and Cloudflare caches it for four
     * hours under a cache key that never changes when the file does. A
     * CSS-only deploy is therefore invisible until that TTL expires.
     *
     * That's worse than a stale tweak when a release renames classes: the
     * new markup ships instantly while the old stylesheet keeps being
     * served, and visitors get a completely unstyled page rather than an
     * out-of-date one. Keying the URL on the file's own mtime gives every
     * change its own cache entry, at both the edge and the browser.
     */
    $stylesheetUrl = asset('css/app.css').'?v='.(@filemtime(public_path('css/app.css')) ?: '0');

    /**
     * `$site` is shared by ResolveSite middleware — the row matching the
     * request's host. Everything identifying below reads from it, so a second
     * domain is a Filament record rather than a fork of this file.
     */
    $siteTitle = ($pageTitle ?? 'Live Cams').' — '.$site->name;
    $siteDescription = $metaDesc ?? $site->homeMeta();
    $logoUrl = $site->logoUrl();
    $faviconUrl = $site->faviconUrl();
    $faviconType = $site->faviconMimeType();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $siteTitle }}</title>
    <meta name="description" content="{{ $siteDescription }}">

    @if (filled($site->meta_keywords))
        <meta name="keywords" content="{{ $site->meta_keywords }}">
    @endif

    @if (!empty($canonicalUrl))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif

    <meta name="robots" content="index,follow">

    @if ($faviconUrl)
        {{--
            This site's own icon, replacing the set below outright: a partial
            override would leave the other domains' tabs showing whichever of
            Foot Queen's files the browser happened to prefer.

            Apple's touch icon is only pointed at the upload when it's a PNG —
            iOS renders nothing else — so an .ico or .svg upload falls through
            to no touch icon at all rather than to another site's artwork.

            The web manifest goes with it, for the same reason: the one in
            public/ names Foot Queen and lists its install icons, so a site
            that has said it wants its own icon is better off without it than
            installed under someone else's name.
        --}}
        @if ($faviconType)
            <link rel="icon" href="{{ $faviconUrl }}" type="{{ $faviconType }}">
        @else
            <link rel="icon" href="{{ $faviconUrl }}">
        @endif
        @if ($faviconType === 'image/png')
            <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
        @endif
    @else
        {{-- Default favicon set (see README for which files to drop into /public/) --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/svg+xml" href="{{ asset('icon.svg') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('favicon-48x48.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    @endif
    <meta name="theme-color" content="{{ $site->theme_color ?? '#0b0b0d' }}">

    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site->name,
            'url' => url('/'),
        ], JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Open Graph — for social sharing --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site->name }}">
    <meta property="og:title" content="{{ $siteTitle }}">
    <meta property="og:description" content="{{ $metaDesc ?? '' }}">
    <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:image" content="{{ $site->ogImageUrl() }}">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,700;9..144,900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ $stylesheetUrl }}">

    {{-- Per-site accent, applied after the stylesheet so it wins without
         needing a separate CSS build or upload per domain. --}}
    @if (filled($site->accent_color))
        <style>:root { --accent: {{ $site->accent_color }}; }</style>
    @endif

    @include('layouts._analytics')

    @stack('head')
</head>
<body class="@yield('bodyClass')">
    <header class="site-header">
        <div class="site-header__inner">
            <a href="{{ route('cams.index') }}" class="logo" aria-label="{{ $site->name }} — Home">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="" class="logo__img">
                @endif
                <span class="logo__text">{{ $site->name }}</span>
            </a>
            <nav class="site-nav">
                {{-- Mostly cross-promotion to the operator's other domains,
                     so it's per-site. External links open in a new tab;
                     on-site paths navigate normally. --}}
                @foreach ($site->nav_links ?? [] as $link)
                    @php($isExternal = \Illuminate\Support\Str::startsWith($link['url'] ?? '', ['http://', 'https://']))
                    <a href="{{ $link['url'] ?? '#' }}" @if ($isExternal) target="_blank" rel="noopener" @endif>{{ $link['label'] ?? '' }}</a>
                @endforeach
            </nav>
            <div class="site-header__meta">
                <span class="live-pulse"></span>
                <span>{{ number_format($totalOnline ?? 0) }} live</span>
            </div>
        </div>
    </header>

    <main class="site-main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div>
            &copy; {{ date('Y') }} {{ $site->name }}. All models are 18+.
            Content sourced from Chaturbate under their
            <a href="https://chaturbate.com/affiliates/" target="_blank" rel="noopener">affiliate program</a>.
            2257 compliance maintained by the source platform.
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
