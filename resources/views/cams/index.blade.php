@extends('layouts.app')

@section('content')
    @include('cams._structured-data')

    <div class="page-heading" style="display: none;">
        <h1>{{ $h1 ?? $site->homeH1() }}</h1>
        <h2 class="page-heading__sub">{{ $site->tagline }}</h2>
        <p class="page-heading__count">
            {{ number_format($cams->total()) }} cams online now
        </p>
    </div>

    @include('cams._filters')

    @if ($cams->isEmpty())
        <div class="empty-state">
            <p>No cams match these filters right now.</p>
            <a href="{{ route('cams.index') }}">Show all</a>
        </div>
    @else
        <section class="cam-grid">
            @foreach ($cams as $cam)
                {{-- Cards open the performer's page on our site (live cam +
                     bio + pics & vids) rather than jumping straight to
                     Chaturbate; `from` tells that page which listing to
                     offer as the way back, and keeps the card click logged
                     under 'grid' as it was before. Same tab, unlike the old
                     outbound link — this is internal navigation now. --}}
                <a href="{{ route('cams.show', ['cam' => $cam->username, 'from' => 'grid']) }}"
                   class="cam-card">
                    <div class="cam-card__thumb">
                        @if ($cam->thumbnail_url)
                            <img src="{{ $cam->thumbnail_url }}"
                                 alt="{{ $cam->username }}"
                                 loading="lazy">
                        @else
                            <div class="cam-card__thumb--placeholder"></div>
                        @endif
                        <div class="cam-card__viewers">
                            <span class="live-dot"></span>
                            {{ number_format($cam->viewers) }}
                        </div>
                        <div class="cam-card__badges">
                            @if ($cam->is_new)<span class="badge badge--new">NEW</span>@endif
                            @if ($cam->is_hd)<span class="badge badge--hd">HD</span>@endif
                        </div>
                    </div>
                    <div class="cam-card__meta">
                        <span class="cam-card__name">{{ $cam->username }}</span>
                        <span class="cam-card__age">
                            @if ($cam->age){{ $cam->age }}@endif
                        </span>
                    </div>
                    <div class="cam-card__tags">
                        @if ($cam->hair_color)<span class="tag">{{ $cam->hair_color }}</span>@endif
                        @if ($cam->body_type)<span class="tag">{{ $cam->body_type }}</span>@endif
                        @foreach (array_slice($cam->categories ?? [], 0, 2) as $cat)
                            <span class="tag tag--muted">#{{ $cat }}</span>
                        @endforeach
                    </div>
                </a>
            @endforeach
        </section>

        <div class="pagination">
            {{ $cams->links() }}
        </div>
    @endif
@endsection
