@extends('layouts.app')

@section('bodyClass', 'is-feed-page')

@section('content')
    @include('cams._structured-data')

    <div class="ig-scroll-hint" id="igScrollHint" aria-hidden="true">
        Scroll for the next live cam <span>&#8595;</span>
    </div>

    <div class="ig-feed">
        <div class="page-heading">
            <h1>{{ $h1 ?? 'FootQueen — Live Feet Cams' }}</h1>
            <h2 class="page-heading__sub">The feed — every live cam, one scroll. Same performers as the <a href="{{ route('cams.index') }}">grid view</a>.</h2>
            <p class="page-heading__count">
                {{ number_format($cams->total()) }} cams online now
            </p>
        </div>

        @if ($cams->isEmpty())
            <div class="empty-state">
                <p>No cams match these filters right now.</p>
                <a href="{{ route('cams.index') }}">Show all</a>
            </div>
        @else
            <div class="ig-posts">
                @foreach ($cams as $cam)
                    <article class="ig-post">
                        <header class="ig-post__header">
                            <span class="ig-post__avatar">
                                @if ($cam->thumbnail_url)
                                    <img src="{{ $cam->thumbnail_url }}" alt="{{ $cam->username }}" loading="lazy">
                                @endif
                            </span>
                            <div class="ig-post__headmeta">
                                <span class="ig-post__user">{{ $cam->username }}</span>
                                <span class="ig-post__sub">
                                    @if ($cam->hair_color){{ ucfirst($cam->hair_color) }}@endif
                                    @if ($cam->body_type) &middot; {{ ucfirst($cam->body_type) }}@endif
                                    @if ($cam->age) &middot; {{ $cam->age }}@endif
                                </span>
                            </div>
                            <span class="ig-post__live"><span class="live-dot"></span> LIVE</span>
                        </header>

                        <a href="{{ route('cams.redirect', [$cam, 'src' => 'feed']) }}"
                           class="ig-post__media"
                           target="_blank"
                           rel="noopener nofollow"
                           @if ($cam->embed_url) data-embed-url="{{ $cam->embed_url }}" @endif>
                            @if ($cam->thumbnail_url)
                                <img src="{{ $cam->thumbnail_url }}" alt="{{ $cam->username }}" loading="lazy">
                            @else
                                <div class="ig-post__media-placeholder"></div>
                            @endif
                            <div class="ig-post__badges">
                                @if ($cam->is_new)<span class="badge badge--new">NEW</span>@endif
                                @if ($cam->is_hd)<span class="badge badge--hd">HD</span>@endif
                            </div>
                            @if ($cam->embed_url)
                                <span class="ig-post__preview-hint">&#9654; Live preview</span>
                            @endif
                        </a>

                        <div class="ig-post__actions">
                            <span class="ig-post__heart" aria-hidden="true">&#9825;</span>
                            <span class="ig-post__count">{{ number_format($cam->viewers) }} watching now</span>
                        </div>

                        <p class="ig-post__caption">
                            <strong>{{ $cam->username }}</strong>
                            @foreach (array_slice($cam->categories ?? [], 0, 4) as $cat)
                                <span class="ig-post__tag">#{{ $cat }}</span>
                            @endforeach
                        </p>

                        <a href="{{ route('cams.redirect', [$cam, 'src' => 'feed']) }}"
                           class="ig-post__cta"
                           target="_blank"
                           rel="noopener nofollow">
                            Join the room &rarr;
                        </a>
                    </article>
                @endforeach
            </div>

            <div class="pagination">
                {{ $cams->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var HOVER_DELAY = 250;   // ms dwell before mounting on desktop hover
            var SETTLE_DELAY = 200;  // ms of stable scroll position before mounting on mobile
            var isTouch = window.matchMedia('(hover: none)').matches;

            var cards = Array.prototype.slice.call(document.querySelectorAll('.ig-post__media[data-embed-url]'));
            if (!cards.length) return;

            var active = null; // { el, iframe }

            function mount(el) {
                if (active && active.el === el) return;
                unmountActive();

                var iframe = document.createElement('iframe');
                iframe.src = el.dataset.embedUrl;
                iframe.className = 'ig-post__live-embed';
                iframe.setAttribute('loading', 'lazy');
                iframe.setAttribute('frameborder', '0');
                iframe.setAttribute('allow', 'autoplay');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                el.appendChild(iframe);
                el.classList.add('ig-post__media--live');

                var post = el.closest('.ig-post');
                if (post) post.classList.add('ig-post--active');

                active = { el: el, iframe: iframe };
            }

            function unmountActive() {
                if (!active) return;
                active.iframe.remove();
                active.el.classList.remove('ig-post__media--live');
                var post = active.el.closest('.ig-post');
                if (post) post.classList.remove('ig-post--active');
                active = null;
            }

            if (isTouch) {
                // Touch/mobile: autoplay whichever card is most visible as the user
                // scrolls, one at a time — like a TikTok/Reels feed.
                var settleTimer = null;

                var observer = new IntersectionObserver(function (entries) {
                    var best = null;

                    entries.forEach(function (entry) {
                        if (active && entry.target === active.el && entry.intersectionRatio < 0.2) {
                            unmountActive();
                        }
                        if (entry.isIntersecting && (!best || entry.intersectionRatio > best.intersectionRatio)) {
                            best = entry;
                        }
                    });

                    if (best && best.intersectionRatio >= 0.6) {
                        clearTimeout(settleTimer);
                        var target = best.target;
                        settleTimer = setTimeout(function () { mount(target); }, SETTLE_DELAY);
                    }
                }, { threshold: [0, 0.2, 0.4, 0.6, 0.8, 1] });

                cards.forEach(function (el) { observer.observe(el); });

                // One-time "keep scrolling" nudge, shown as soon as the first
                // card auto-activates and dismissed on the user's first scroll.
                var hint = document.getElementById('igScrollHint');
                if (hint && cards.length > 1) {
                    var revealTimer = setTimeout(function () {
                        if (active) hint.classList.add('is-visible');
                    }, SETTLE_DELAY + 150);

                    window.addEventListener('scroll', function () {
                        clearTimeout(revealTimer);
                        hint.classList.remove('is-visible');
                    }, { once: true, passive: true });
                }
            } else {
                // Desktop: preview on hover / keyboard focus.
                cards.forEach(function (el) {
                    var hoverTimer = null;

                    el.addEventListener('mouseenter', function () {
                        hoverTimer = setTimeout(function () { mount(el); }, HOVER_DELAY);
                    });
                    el.addEventListener('mouseleave', function () {
                        clearTimeout(hoverTimer);
                        if (active && active.el === el) unmountActive();
                    });
                    el.addEventListener('focus', function () { mount(el); });
                    el.addEventListener('blur', function () {
                        if (active && active.el === el) unmountActive();
                    });
                });
            }
        })();
    </script>
@endpush
