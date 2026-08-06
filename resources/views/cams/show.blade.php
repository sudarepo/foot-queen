@extends('layouts.app')

@section('bodyClass', 'is-profile-page')

@php
    use Illuminate\Support\Number;

    /**
     * One outbound URL for the whole page. Every clickable piece of content
     * here — the cover, the CTA, each locked set — is a route into the same
     * room, so they all share the affiliate link and the same click source.
     */
    $roomUrl = route('cams.redirect', [$cam, 'src' => 'profile']);

    $backRoute = $backTo === 'feed' ? route('cams.feed') : route('cams.index');
    $backLabel = $backTo === 'feed' ? 'Back to the feed' : 'Back to all cams';

    $videoCount = count(array_filter($photoSets, fn ($set) => $set['is_video']));
    $photoCount = count($photoSets) - $videoCount;
    $followers = $cam->profileAttribute('follower_count');

    /** Small facts that get their own icon row, in the order they read best. */
    $details = array_filter([
        'location' => $cam->profileAttribute('location'),
        'languages' => $cam->profileAttribute('languages'),
        'birthday' => $cam->profileAttribute('birthday'),
    ]);

    /** Everything else, as chips. */
    $chips = array_filter([
        $cam->age ? $cam->age.' yrs' : null,
        $cam->hair_color ? ucfirst($cam->hair_color).' hair' : null,
        $cam->body_type ? ucfirst($cam->body_type) : null,
        $cam->profileAttribute('body_stats'),
        is_array($cam->profileAttribute('interested_in'))
            ? 'Into '.implode(', ', $cam->profileAttribute('interested_in'))
            : null,
    ]);

    $icons = [
        'location' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="2.8"/></svg>',
        'languages' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z"/></svg>',
        'birthday' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16v-7H4zM4 13a2.5 2.5 0 0 0 4 0 2.5 2.5 0 0 0 4 0 2.5 2.5 0 0 0 4 0 2.5 2.5 0 0 0 4 0"/><path d="M12 5v3M9 6.5V8M15 6.5V8"/></svg>',
    ];
@endphp

@push('head')
    {{-- Warm the connection ahead of the cover embed — DNS/TCP/TLS setup can
         be several hundred ms, and this is the first thing on the page. --}}
    <link rel="preconnect" href="https://cbxyz.com">
    <link rel="dns-prefetch" href="https://cbxyz.com">

    <script type="application/ld+json">
        {!! json_encode(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'name' => $cam->username,
            'url' => $canonicalUrl,
            'description' => $metaDesc,
            'mainEntity' => array_filter([
                '@type' => 'Person',
                'name' => $cam->username,
                'image' => $cam->thumbnail_url,
                'homeLocation' => $cam->profileAttribute('location'),
                'knowsLanguage' => $cam->profileAttribute('languages'),
                'interactionStatistic' => $followers ? array_filter([
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/FollowAction',
                    'userInteractionCount' => $followers,
                ]) : null,
            ]),
        ]), JSON_UNESCAPED_SLASHES) !!}
    </script>
@endpush

@section('content')
    <div class="pf">
        {{-- The cover is the live room itself: a creator-page banner where the
             banner happens to be broadcasting. Keeps the video at the very top
             instead of below a wall of profile furniture. --}}
        <div class="pf__cover">
            <a href="{{ $backRoute }}" class="pf__back" aria-label="{{ $backLabel }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                <span>{{ $backLabel }}</span>
            </a>

            <a href="{{ $roomUrl }}"
               class="pf__stage @if (! $cam->is_online) pf__stage--offline @endif"
               id="profileStage"
               target="_blank"
               rel="noopener nofollow"
               @if ($cam->is_online && $cam->embed_url) data-embed-url="{{ $cam->embed_url }}" @endif>
                @if ($cam->thumbnail_url)
                    <img src="{{ $cam->thumbnail_url }}" alt="{{ $cam->username }} live cam" fetchpriority="high">
                @else
                    <div class="pf__stage-empty"></div>
                @endif

                <span class="pf__stage-scrim"></span>

                @if ($cam->is_online)
                    <span class="pf__livetag"><span class="live-dot"></span> LIVE</span>
                    <span class="pf__viewers">{{ number_format($cam->viewers) }} watching</span>
                    @if ($cam->embed_url)
                        <span class="pf__stage-hint">&#9654; Live preview</span>
                    @endif
                @else
                    <span class="pf__stage-plate">Offline right now</span>
                @endif
            </a>
        </div>

        {{-- Identity block, overlapping the cover the way a creator page does. --}}
        <header class="pf__id">
            <a href="{{ $roomUrl }}" class="pf__avatar @if ($cam->is_online) is-live @endif" target="_blank" rel="noopener nofollow">
                @if ($cam->thumbnail_url)
                    <img src="{{ $cam->thumbnail_url }}" alt="" width="88" height="88">
                @endif
            </a>

            <div class="pf__idmeta">
                <h1 class="pf__name">
                    {{ $cam->username }}
                    @if ($cam->is_hd)<span class="pf__hd" title="Streams in HD">HD</span>@endif
                </h1>
                <p class="pf__handle">
                    <span>&#64;{{ $cam->username }}</span>
                    @if ($cam->is_online)
                        <span class="pf__avail"><span class="live-dot"></span> Available now</span>
                    @else
                        <span class="pf__unavail">Offline</span>
                    @endif
                </p>
            </div>
        </header>

        <ul class="pf__stats">
            @if ($followers)
                <li>
                    <b title="{{ number_format($followers) }} followers">{{ Number::abbreviate($followers, maxPrecision: 1) }}</b>
                    <span>Followers</span>
                </li>
            @endif
            @if ($videoCount)
                <li><b>{{ $videoCount }}</b><span>{{ Str::plural('Video', $videoCount) }}</span></li>
            @endif
            @if ($photoCount)
                <li><b>{{ $photoCount }}</b><span>{{ Str::plural('Photo set', $photoCount) }}</span></li>
            @endif
            @if ($cam->is_online)
                <li><b>{{ Number::abbreviate($cam->viewers, maxPrecision: 1) }}</b><span>Watching</span></li>
            @endif
        </ul>

        @if ($cam->room_subject)
            <p class="pf__subject">{{ $cam->room_subject }}</p>
        @endif

        @if (filled($cam->bio))
            {{-- Stored as plain text — BioSanitizer strips the markup, affiliate
                 links and hidden nodes these bios ship with — so paragraphs are
                 rebuilt here rather than trusted. --}}
            <div class="pf__bio" id="profileBio">
                @foreach (preg_split('/\n{2,}/', $cam->bio) as $paragraph)
                    <p>{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
            </div>
            {{-- Collapsing is applied by script, not rendered in: without JS the
                 bio stays fully readable rather than being cut off behind a
                 button that can't work. --}}
            <button type="button" class="pf__bio-toggle" id="profileBioToggle" hidden>Show more</button>
        @endif

        @if ($details !== [])
            <ul class="pf__details">
                @foreach ($details as $key => $value)
                    <li>{!! $icons[$key] !!}<span>{{ $value }}</span></li>
                @endforeach
            </ul>
        @endif

        @if ($chips !== [])
            <ul class="pf__chips">
                @foreach ($chips as $chip)
                    <li>{{ $chip }}</li>
                @endforeach
            </ul>
        @endif

        {{-- The subscribe box of a creator page, except the room is free to
             enter — which is the strongest thing we can say here. --}}
        <div class="pf__join">
            <a href="{{ $roomUrl }}" class="pf__cta" target="_blank" rel="noopener nofollow">
                @if ($cam->is_online)
                    Watch {{ $cam->username }} live
                @else
                    Open {{ $cam->username }}&rsquo;s room
                @endif
                <span aria-hidden="true">&rarr;</span>
            </a>
            <p class="pf__join-note">Free to watch &middot; opens on Chaturbate</p>
        </div>

        @if ($cam->categories)
            <ul class="pf__tags">
                @foreach ($cam->categories as $category)
                    {{-- Internal, unlike everything else here: a tag is
                         navigation into our own filtered listing. --}}
                    <li><a href="{{ route('cams.index', ['category' => $category]) }}">#{{ $category }}</a></li>
                @endforeach
            </ul>
        @endif

        @if ($photoSets !== [])
            <section class="pf__media">
                <div class="pf__media-head">
                    <h2>Pics &amp; Vids</h2>
                    @if ($videoCount && $photoCount)
                        <div class="pf__tabs" role="tablist">
                            <button type="button" class="pf__tab is-active" data-filter="all" role="tab" aria-selected="true">All {{ count($photoSets) }}</button>
                            <button type="button" class="pf__tab" data-filter="video" role="tab" aria-selected="false">Videos {{ $videoCount }}</button>
                            <button type="button" class="pf__tab" data-filter="photo" role="tab" aria-selected="false">Photos {{ $photoCount }}</button>
                        </div>
                    @endif
                </div>

                <div class="pf__grid" id="profileGrid" data-filter="all">
                    @foreach ($photoSets as $set)
                        @php $label = $set['name'] ?: ($set['is_video'] ? 'Video' : 'Photo set'); @endphp
                        <a href="{{ $roomUrl }}"
                           class="pf-tile @if ($set['is_video']) is-video @else is-photo @endif"
                           title="{{ $label }}"
                           target="_blank"
                           rel="noopener nofollow">
                            <img src="{{ $set['cover_url'] }}" alt="{{ $label }}" loading="lazy">

                            <span class="pf-tile__scrim"></span>

                            {{-- Locked, but the cover stays unblurred: it's the
                                 still the performer chose to sell the set with. --}}
                            <span class="pf-tile__lock" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>
                            </span>

                            @if ($set['is_video'])
                                <span class="pf-tile__play" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.2v13.6L19 12z"/></svg>
                                </span>
                            @endif

                            <span class="pf-tile__foot">
                                <span class="pf-tile__count">
                                    @if ($set['is_video'] && $set['duration_seconds'])
                                        {{ gmdate($set['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $set['duration_seconds']) }}
                                    @elseif (! $set['is_video'] && $set['photo_count'])
                                        {{ $set['photo_count'] }} pics
                                    @endif
                                </span>
                                <span class="pf-tile__price">
                                    @if ($set['fan_club_only'])
                                        Fan club
                                    @elseif ($set['tokens'] > 0)
                                        {{ number_format($set['tokens']) }} tk
                                    @else
                                        Free
                                    @endif
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>

                <p class="pf__media-note">
                    Pics and videos unlock on Chaturbate &mdash; tap any set to open {{ $cam->username }}&rsquo;s room.
                </p>
            </section>
        @endif

        <nav class="pf__foot">
            <a href="{{ $backRoute }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                {{ $backLabel }}
            </a>
        </nav>
    </div>

    {{-- Phones only: the CTA follows you down the page, the way a creator
         page keeps its subscribe button in reach. --}}
    <div class="pf__dock">
        <a href="{{ $roomUrl }}" class="pf__cta" target="_blank" rel="noopener nofollow">
            @if ($cam->is_online)
                Watch {{ $cam->username }} live
            @else
                Open {{ $cam->username }}&rsquo;s room
            @endif
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>
@endsection

@push('scripts')
    <script>
        /*
         * Long bios get clamped with a "show more" toggle.
         *
         * Performers use the bio field as a layout surface — a full tip menu
         * comes through as twenty-odd one-line paragraphs ("10", "Send Pm",
         * "50", …), which on a phone pushes Pics & Vids clean off the bottom
         * of the page. Short bios are left alone.
         */
        (function () {
            var bio = document.getElementById('profileBio');
            var toggle = document.getElementById('profileBioToggle');
            if (!bio || !toggle) return;

            var CLAMP_HEIGHT = 190;
            if (bio.scrollHeight <= CLAMP_HEIGHT + 50) return;

            bio.classList.add('is-clamped');
            toggle.hidden = false;

            toggle.addEventListener('click', function () {
                bio.classList.remove('is-clamped');
                toggle.hidden = true;
            });
        })();

        /* Media tabs. Filtering is a class on the grid, so no tile is ever
           removed from the DOM and nothing has to be re-rendered. */
        (function () {
            var grid = document.getElementById('profileGrid');
            if (!grid) return;

            var tabs = document.querySelectorAll('.pf__tab');

            Array.prototype.forEach.call(tabs, function (tab) {
                tab.addEventListener('click', function () {
                    grid.dataset.filter = tab.dataset.filter;

                    Array.prototype.forEach.call(tabs, function (other) {
                        var active = other === tab;
                        other.classList.toggle('is-active', active);
                        other.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                });
            });
        })();

        /* The live embed, mounted when the cover scrolls into view. */
        (function () {
            var stage = document.getElementById('profileStage');
            if (!stage || !stage.dataset.embedUrl || !window.IntersectionObserver) return;

            var observer = new IntersectionObserver(function (entries, self) {
                if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
                self.disconnect();

                var iframe = document.createElement('iframe');
                iframe.src = stage.dataset.embedUrl;
                iframe.className = 'pf__stage-embed';
                iframe.setAttribute('frameborder', '0');
                iframe.setAttribute('allow', 'autoplay');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                stage.appendChild(iframe);

                // Same trick as the feed: a cross-origin iframe swallows
                // wheel/touch input, so a transparent overlay in our own
                // document sits on top and lets scroll and clicks through.
                var overlay = document.createElement('div');
                overlay.className = 'pf__stage-overlay';
                stage.appendChild(overlay);

                stage.classList.add('pf__stage--live');
            }, { threshold: 0.25 });

            observer.observe(stage);
        })();
    </script>
@endpush
