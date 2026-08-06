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
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $pageTitle ?? 'Live Cams' }} — Foot Queen Cams</title>
    <meta name="description" content="{{ $metaDesc ?? 'Watch sexy feet, soles, toes, and foot worship cams streaming 24/7 from verified performers.' }}">

    @if (!empty($canonicalUrl))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif

    <meta name="robots" content="index,follow">

    {{-- Favicon set (see README for which files to drop into /public/) --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('icon.svg') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('favicon-48x48.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#0b0b0d">

    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Foot Queen Cams',
            'url' => url('/'),
        ], JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Open Graph — for social sharing --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Foot Queen Cams">
    <meta property="og:title" content="{{ $pageTitle ?? 'Live Cams' }} — Foot Queen Cams">
    <meta property="og:description" content="{{ $metaDesc ?? '' }}">
    <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,700;9..144,900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ $stylesheetUrl }}">

    @stack('head')
</head>
<body class="@yield('bodyClass')">
    <header class="site-header">
        <div class="site-header__inner">
            <a href="{{ route('cams.index') }}" class="logo" aria-label="Foot Queen Cams — Home">
                @if (file_exists(public_path('img/logo.png')))
                    <img src="{{ asset('img/logo.png') }}" alt="" class="logo__img">
                @endif
                <span class="logo__text">Foot Queen Cams</span>
            </a>
            <nav class="site-nav">
                <a href="/girls">Girls</a>
                <a href="http://gaycams.xxx" target="_blank" rel="noopener">Guys</a>
                <a href="http://besttrannysex.com" target="_blank" rel="noopener">Trans</a>
                <a href="http://erotictelevision.net" target="_blank" rel="noopener">Couples</a>
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
            &copy; {{ date('Y') }} Foot Queen Cams. All models are 18+.
            Content sourced from Chaturbate under their
            <a href="https://chaturbate.com/affiliates/" target="_blank" rel="noopener">affiliate program</a>.
            2257 compliance maintained by the source platform.
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
