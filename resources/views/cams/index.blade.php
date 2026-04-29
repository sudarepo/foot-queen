@extends('layouts.app')

@section('content')
    <div class="page-heading">
        <h1>{{ $h1 ?? 'FootQueen — Live Feet Cams' }}</h1>
        <h2 class="page-heading__sub">Watch sexy feet, soles, toes, and foot worship cams streaming 24/7 from verified performers.</h2>
        <p class="page-heading__count">
            {{ number_format($cams->total()) }} cams online now
        </p>
    </div>

    <section class="filters-bar">
        <form method="GET" action="{{ url()->current() }}" class="filters">
            @foreach (['category' => 'category', 'age' => 'age', 'hair' => 'hair', 'body' => 'body'] as $paramName => $metaKey)
                <label class="filter">
                    <span class="filter__label">{{ ucfirst($paramName === 'age' ? 'Age' : $paramName) }}</span>
                    <select name="{{ $paramName }}" onchange="this.form.submit()">
                        @foreach ($filterMeta[$metaKey] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(($filters[$metaKey === 'age' ? 'age_range' : ($metaKey === 'hair' ? 'hair_color' : ($metaKey === 'body' ? 'body_type' : $metaKey))] ?? '') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endforeach

            @if (!empty($filters))
                <a href="{{ url()->current() }}" class="filter-reset">Clear ×</a>
            @endif
        </form>
    </section>

    @if ($cams->isEmpty())
        <div class="empty-state">
            <p>No cams match these filters right now.</p>
            <a href="{{ route('cams.index') }}">Show all</a>
        </div>
    @else
        <section class="cam-grid">
            @foreach ($cams as $cam)
                <a href="{{ route('cams.redirect', $cam) }}"
                   class="cam-card"
                   target="_blank"
                   rel="noopener nofollow">
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
