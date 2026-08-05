@extends('layouts.app')

@section('bodyClass', 'is-feed-page')

@push('head')
    {{-- Warm the connection ahead of the first preview — skips DNS/TCP/TLS
         setup (can be several hundred ms) on the request that actually
         matters instead of paying it cold on first hover/scroll-into-view. --}}
    <link rel="preconnect" href="https://cbxyz.com">
    <link rel="dns-prefetch" href="https://cbxyz.com">
    <link rel="preconnect" href="https://chaturbate.com">
    <link rel="dns-prefetch" href="https://chaturbate.com">
@endpush

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
            <p class="ig-feed__notice">
                Live video previews need third-party embeds allowed — an ad blocker or
                strict privacy mode may block them. "Join the room" always works either way.
            </p>
        </div>

        @if ($cams->isEmpty())
            <div class="empty-state">
                <p>No cams match these filters right now.</p>
                <a href="{{ route('cams.index') }}">Show all</a>
            </div>
        @else
            <div class="ig-posts" id="igPosts">
                @include('cams._feed-posts')
            </div>

            {{-- Infinite scroll anchor. The href is a real link to the next
                 page, so this works as plain "load more" pagination without
                 JS (and stays crawlable); the script below hides it and
                 fetches batches automatically as the anchor scrolls into
                 view, restoring it if a fetch fails. --}}
            @if ($cams->hasMorePages())
                <div class="ig-feed__more" id="igFeedMore" data-next-url="{{ $cams->nextPageUrl() }}">
                    <span class="ig-feed__more-spinner" aria-hidden="true"></span>
                    <a class="ig-feed__more-link" href="{{ $cams->nextPageUrl() }}" rel="next">Load more cams</a>
                </div>
            @endif

            <p class="ig-feed__end" id="igFeedEnd" hidden>You&rsquo;ve reached the end of the feed.</p>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var HOVER_DELAY = 250;   // ms dwell before mounting on desktop hover
            var SETTLE_DELAY = 200;  // ms of stable scroll position before mounting on mobile
            var isTouch = window.matchMedia('(hover: none)').matches;

            // Grows as infinite scroll appends batches; always in feed order,
            // which the "pre-buffer the next card" logic below relies on.
            var cards = [];

            var active = null;     // { el, iframe, overlay } — visible & playing, at most one
            var preloading = null; // { el, iframe } — hidden, warming up in the background, at most one

            function createIframe(el) {
                var iframe = document.createElement('iframe');
                iframe.src = el.dataset.embedUrl;
                iframe.className = 'ig-post__live-embed';
                iframe.setAttribute('frameborder', '0');
                iframe.setAttribute('allow', 'autoplay');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                return iframe;
            }

            // Starts the network request early, before the card is actually shown —
            // hidden (opacity: 0, not interactive) so it doesn't flash on screen.
            // Capped at exactly one in flight: this is a head start on the *next*
            // likely card, not a bulk preload of several simultaneous live streams.
            function startPreload(el) {
                if ((preloading && preloading.el === el) || (active && active.el === el)) return;
                cancelPreload();
                var iframe = createIframe(el);
                iframe.classList.add('ig-post__live-embed--preloading');
                el.appendChild(iframe);
                preloading = { el: el, iframe: iframe };
            }

            function cancelPreload() {
                if (!preloading) return;
                preloading.iframe.remove();
                preloading = null;
            }

            // Reveals a card as the active/playing one — reusing an already-warming
            // preload for it if one exists, so nothing extra loads twice.
            function activate(el) {
                if (active && active.el === el) return;
                unmountActive();

                var iframe;
                if (preloading && preloading.el === el) {
                    iframe = preloading.iframe;
                    iframe.classList.remove('ig-post__live-embed--preloading');
                    preloading = null;
                } else {
                    cancelPreload();
                    iframe = createIframe(el);
                    el.appendChild(iframe);
                }

                // A cross-origin iframe swallows wheel/touch-scroll input once the
                // cursor is over it — the parent page has no visibility into that
                // separate document, so it can't forward the scroll. A transparent
                // overlay in OUR document sits on top instead: normal DOM element,
                // so scroll/click bubble up normally (scrolls the page, clicks
                // still activate the card's link) as if the iframe weren't there.
                var overlay = document.createElement('div');
                overlay.className = 'ig-post__live-embed-overlay';
                el.appendChild(overlay);

                el.classList.add('ig-post__media--live');

                var post = el.closest('.ig-post');
                if (post) post.classList.add('ig-post--active');

                active = { el: el, iframe: iframe, overlay: overlay };
            }

            function unmountActive() {
                if (!active) return;
                active.iframe.remove();
                active.overlay.remove();
                active.el.classList.remove('ig-post__media--live');
                var post = active.el.closest('.ig-post');
                if (post) post.classList.remove('ig-post--active');
                active = null;
            }

            var previewObserver = null;
            var settleTimer = null;

            if (isTouch) {
                // Touch/mobile: autoplay whichever card is most visible as the user
                // scrolls, one at a time — like a TikTok/Reels feed.
                previewObserver = new IntersectionObserver(function (entries) {
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
                        settleTimer = setTimeout(function () {
                            activate(target);
                            // Pre-buffer the next card in scroll order — the same
                            // trick TikTok/Reels use — so scrolling down lands on
                            // something already warmed up instead of a cold load.
                            var next = cards[cards.indexOf(target) + 1];
                            if (next) startPreload(next);
                        }, SETTLE_DELAY);
                    }
                }, { threshold: [0, 0.2, 0.4, 0.6, 0.8, 1] });
            }

            // Desktop: preview on hover / keyboard focus.
            function bindHoverPreview(el) {
                var hoverTimer = null;

                el.addEventListener('mouseenter', function () {
                    // Start loading immediately so the fetch runs *during* the
                    // dwell window instead of after it — by the time the dwell
                    // elapses (if the user is still hovering), it's often
                    // already loaded and just needs revealing.
                    startPreload(el);
                    hoverTimer = setTimeout(function () { activate(el); }, HOVER_DELAY);
                });
                el.addEventListener('mouseleave', function () {
                    clearTimeout(hoverTimer);
                    if (preloading && preloading.el === el) cancelPreload();
                    if (active && active.el === el) unmountActive();
                });
                el.addEventListener('focus', function () { activate(el); });
                el.addEventListener('blur', function () {
                    if (active && active.el === el) unmountActive();
                });
            }

            // Cards arrive in two waves: the server-rendered first page, then
            // every batch the infinite scroll appends. Both go through here so
            // a card behaves identically whenever it entered the DOM. Already
            // known cards are skipped, so re-scanning the container is cheap.
            function registerCards(root) {
                var found = root.querySelectorAll('.ig-post__media[data-embed-url]');

                Array.prototype.forEach.call(found, function (el) {
                    if (cards.indexOf(el) !== -1) return;
                    cards.push(el);

                    if (isTouch) {
                        previewObserver.observe(el);
                    } else {
                        bindHoverPreview(el);
                    }
                });
            }

            registerCards(document);

            if (isTouch) {
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
            }

            /* ----------  Infinite scroll  ---------- */

            var posts = document.getElementById('igPosts');
            var more = document.getElementById('igFeedMore');
            var end = document.getElementById('igFeedEnd');

            if (!posts || !more || !window.fetch || !window.IntersectionObserver) return;

            var nextUrl = more.dataset.nextUrl || '';
            var loading = false;

            // Marks the "load more" link as script-driven, which hides it —
            // it comes back if a batch fails so the user still has a way on.
            more.classList.add('is-auto');

            function finish() {
                scrollObserver.disconnect();
                more.remove();
                if (end) end.hidden = false;
            }

            function loadNextBatch() {
                if (loading || !nextUrl) return;
                loading = true;
                more.classList.remove('has-error');
                more.classList.add('is-loading');

                var url = nextUrl + (nextUrl.indexOf('?') === -1 ? '?' : '&') + 'partial=1';

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        return response.json();
                    })
                    .then(function (data) {
                        var buffer = document.createElement('div');
                        buffer.innerHTML = data.html;

                        while (buffer.firstElementChild) {
                            posts.appendChild(buffer.firstElementChild);
                        }

                        registerCards(posts);

                        nextUrl = data.next_page_url || '';
                        loading = false;
                        more.classList.remove('is-loading');

                        if (!nextUrl) {
                            finish();
                            return;
                        }

                        more.dataset.nextUrl = nextUrl;
                        var link = more.querySelector('.ig-feed__more-link');
                        if (link) link.href = nextUrl;

                        // The anchor may still be on screen after a short
                        // batch — observing it again re-fires only if it is.
                        scrollObserver.unobserve(more);
                        scrollObserver.observe(more);
                    })
                    .catch(function () {
                        // Fall back to the plain link rather than retrying in a
                        // loop against whatever is failing.
                        loading = false;
                        more.classList.remove('is-loading');
                        more.classList.add('has-error');
                        scrollObserver.unobserve(more);
                    });
            }

            // rootMargin starts the next batch while the anchor is still a
            // screenful below the fold, so posts are usually in place before
            // the user scrolls that far.
            var scrollObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) loadNextBatch();
                });
            }, { rootMargin: '800px 0px' });

            scrollObserver.observe(more);

            // After an error the link is visible again; clicking it retries in
            // place instead of navigating away and losing the user's position.
            more.addEventListener('click', function (event) {
                var link = event.target.closest('.ig-feed__more-link');
                if (!link) return;
                event.preventDefault();
                scrollObserver.observe(more);
                loadNextBatch();
            });
        })();
    </script>
@endpush
