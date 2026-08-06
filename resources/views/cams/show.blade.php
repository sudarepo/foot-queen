@extends('layouts.app')

@section('bodyClass', 'is-profile-page')

@php
    /**
     * One outbound URL for the whole page. Every clickable piece of content
     * here — the stage, the CTA, each photo set — is a route into the same
     * room, so they all share the affiliate link and the same click source.
     */
    $roomUrl = route('cams.redirect', [$cam, 'src' => 'profile']);

    $backRoute = $backTo === 'feed' ? route('cams.feed') : route('cams.index');
    $backLabel = $backTo === 'feed' ? 'Back to the feed' : 'Back to all cams';

    $facts = array_filter([
        'Age' => $cam->age ?: $cam->profileAttribute('display_age'),
        'From' => $cam->profileAttribute('location'),
        'Speaks' => $cam->profileAttribute('languages'),
        'Hair' => $cam->hair_color ? ucfirst($cam->hair_color) : null,
        'Body' => $cam->body_type ? ucfirst($cam->body_type) : null,
        'Stats' => $cam->profileAttribute('body_stats'),
        'Birthday' => $cam->profileAttribute('birthday'),
        'Into' => is_array($cam->profileAttribute('interested_in'))
            ? implode(', ', $cam->profileAttribute('interested_in'))
            : $cam->profileAttribute('interested_in'),
    ]);

    $followers = $cam->profileAttribute('follower_count');
@endphp

@push('head')
    {{-- Same warm-up as the feed: the live embed is the point of this page,
         so pay DNS/TCP/TLS setup before it's needed rather than on the
         request that matters. --}}
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
    <div class="profile">
        {{-- Sticky on mobile: this page is a scroll, and losing the way back
             behind a long bio is the fastest way to lose the visit. --}}
        <nav class="profile__back">
            <a href="{{ $backRoute }}" class="profile__back-link">
                <span aria-hidden="true">&larr;</span> {{ $backLabel }}
            </a>
        </nav>

        <header class="profile__head">
            <span class="profile__avatar">
                @if ($cam->thumbnail_url)
                    <img src="{{ $cam->thumbnail_url }}" alt="" width="64" height="64">
                @endif
            </span>
            <div class="profile__headmeta">
                <h1 class="profile__name">{{ $cam->username }}</h1>
                <p class="profile__status">
                    @if ($cam->is_online)
                        <span class="profile__live"><span class="live-dot"></span> LIVE</span>
                        <span class="profile__viewers">{{ number_format($cam->viewers) }} watching</span>
                    @else
                        <span class="profile__offline">Offline right now</span>
                    @endif
                    @if ($followers)
                        <span class="profile__followers">{{ number_format($followers) }} followers</span>
                    @endif
                </p>
            </div>
            <div class="profile__badges">
                @if ($cam->is_new)<span class="badge badge--new">NEW</span>@endif
                @if ($cam->is_hd)<span class="badge badge--hd">HD</span>@endif
            </div>
        </header>

        {{-- The live room. Online performers get the real embed mounted by the
             script below (lazily, once it scrolls into view); offline ones get
             the last thumbnail with an offline plate over it. Either way the
             whole stage is a link out to the room. --}}
        <a href="{{ $roomUrl }}"
           class="profile__stage @if (! $cam->is_online) profile__stage--offline @endif"
           id="profileStage"
           target="_blank"
           rel="noopener nofollow"
           @if ($cam->is_online && $cam->embed_url) data-embed-url="{{ $cam->embed_url }}" @endif>
            @if ($cam->thumbnail_url)
                <img src="{{ $cam->thumbnail_url }}" alt="{{ $cam->username }} live cam" fetchpriority="high">
            @else
                <div class="profile__stage-placeholder"></div>
            @endif

            @if (! $cam->is_online)
                <span class="profile__stage-plate">
                    Offline &mdash; check the live cams
                </span>
            @elseif ($cam->embed_url)
                <span class="profile__stage-hint">&#9654; Live preview</span>
            @endif
        </a>

        @if ($cam->room_subject)
            <p class="profile__subject">{{ $cam->room_subject }}</p>
        @endif

        <a href="{{ $roomUrl }}" class="profile__cta" target="_blank" rel="noopener nofollow">
            @if ($cam->is_online)
                Join {{ $cam->username }}&rsquo;s room &rarr;
            @else
                Open {{ $cam->username }}&rsquo;s room on Chaturbate &rarr;
            @endif
        </a>

        @if ($cam->categories)
            <div class="profile__tags">
                @foreach ($cam->categories as $category)
                    {{-- Internal, unlike everything else here: a tag is
                         navigation into our own filtered listing. --}}
                    <a href="{{ route('cams.index', ['category' => $category]) }}" class="tag tag--muted">#{{ $category }}</a>
                @endforeach
            </div>
        @endif

        @if ($facts !== [])
            <section class="profile__section">
                <h2 class="profile__section-title">About {{ $cam->username }}</h2>
                <dl class="profile__facts">
                    @foreach ($facts as $label => $value)
                        <div class="profile__fact">
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if (filled($cam->bio))
            <section class="profile__section">
                <h2 class="profile__section-title">Bio</h2>
                {{-- Stored as plain text — BioSanitizer strips the markup,
                     affiliate links and hidden nodes these bios ship with —
                     so paragraphs are rebuilt here rather than trusted. --}}
                <div class="profile__bio" id="profileBio">
                    @foreach (preg_split('/\n{2,}/', $cam->bio) as $paragraph)
                        <p>{!! nl2br(e($paragraph)) !!}</p>
                    @endforeach
                </div>
                {{-- Collapsing is applied by script, not rendered in: without
                     JS the bio stays fully readable rather than being cut off
                     behind a button that can't work. --}}
                <button type="button" class="profile__bio-toggle" id="profileBioToggle" hidden>
                    Show full bio
                </button>
            </section>
        @endif

        @if ($photoSets !== [])
            <section class="profile__section">
                <h2 class="profile__section-title">
                    Pics &amp; Vids
                    <span class="profile__section-count">{{ count($photoSets) }}</span>
                </h2>

                <div class="profile__sets">
                    @foreach ($photoSets as $set)
                        <a href="{{ $roomUrl }}" class="photo-set" target="_blank" rel="noopener nofollow">
                            <span class="photo-set__cover">
                                <img src="{{ $set['cover_url'] }}"
                                     alt="{{ $set['name'] ?: ($set['is_video'] ? 'Video' : 'Photo set') }}"
                                     loading="lazy">
                                @if ($set['is_video'])
                                    <span class="photo-set__play" aria-hidden="true">&#9654;</span>
                                    @if ($set['duration_seconds'])
                                        <span class="photo-set__duration">
                                            {{ gmdate($set['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $set['duration_seconds']) }}
                                        </span>
                                    @endif
                                @elseif ($set['photo_count'])
                                    <span class="photo-set__duration">{{ $set['photo_count'] }} pics</span>
                                @endif
                            </span>
                            <span class="photo-set__meta">
                                <span class="photo-set__name">{{ $set['name'] ?: ($set['is_video'] ? 'Video' : 'Photo set') }}</span>
                                <span class="photo-set__price">
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

                <p class="profile__sets-note">
                    Pics and videos unlock on Chaturbate &mdash; tap any set to open {{ $cam->username }}&rsquo;s room.
                </p>
            </section>
        @endif

        <nav class="profile__foot">
            <a href="{{ $backRoute }}" class="profile__foot-link">
                <span aria-hidden="true">&larr;</span> {{ $backLabel }}
            </a>
        </nav>
    </div>
@endsection

@push('scripts')
    <script>
        /*
         * Long bios get clamped with a "show full bio" toggle.
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

            var CLAMP_HEIGHT = 260;
            if (bio.scrollHeight <= CLAMP_HEIGHT + 60) return;

            bio.classList.add('is-clamped');
            toggle.hidden = false;

            toggle.addEventListener('click', function () {
                bio.classList.remove('is-clamped');
                toggle.hidden = true;
            });
        })();

        (function () {
            var stage = document.getElementById('profileStage');
            if (!stage || !stage.dataset.embedUrl || !window.IntersectionObserver) return;

            // Mounted on scroll-into-view rather than at load: the stage is
            // usually already in view, so this is really a "don't start a live
            // video stream for someone who bounced in the first 200ms" guard.
            var observer = new IntersectionObserver(function (entries, self) {
                if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
                self.disconnect();

                var iframe = document.createElement('iframe');
                iframe.src = stage.dataset.embedUrl;
                iframe.className = 'profile__stage-embed';
                iframe.setAttribute('frameborder', '0');
                iframe.setAttribute('allow', 'autoplay');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                stage.appendChild(iframe);

                // Same trick as the feed: a cross-origin iframe swallows
                // wheel/touch input, so a transparent overlay in our own
                // document sits on top and lets scroll and clicks through.
                var overlay = document.createElement('div');
                overlay.className = 'profile__stage-overlay';
                stage.appendChild(overlay);

                stage.classList.add('profile__stage--live');
            }, { threshold: 0.25 });

            observer.observe(stage);
        })();
    </script>
@endpush
