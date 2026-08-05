{{-- One page of feed posts. Rendered inline on first load and returned on its
     own (as JSON-embedded HTML) for each infinite-scroll batch, so a card is
     built from exactly one template either way. See CamController::feed(). --}}
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
