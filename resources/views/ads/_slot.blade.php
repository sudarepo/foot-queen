@php
    $slot = app(\App\Services\AdServer::class)->resolve($placement);
@endphp

@if ($slot)
    <div class="ad-slot ad-slot--{{ $placement->value }}" aria-label="Advertisement">
        @if ($slot['ad']->isEmbed())
            {{-- Admin-only field: third-party ad tags must render unescaped. --}}
            <div class="ad-slot__embed">{!! $slot['ad']->embed_code !!}</div>
        @else
            <a class="ad-slot__link"
               href="{{ $slot['clickUrl'] }}"
               target="_blank"
               rel="sponsored noopener nofollow">
                @if ($slot['ad']->imageUrl())
                    <img class="ad-slot__image"
                         src="{{ $slot['ad']->imageUrl() }}"
                         alt="{{ $slot['ad']->alt_text ?? $slot['ad']->creative }}"
                         loading="eager"
                         decoding="async">
                @else
                    <span class="ad-slot__fallback">
                        {{ $slot['ad']->creative }}
                    </span>
                @endif
            </a>
        @endif

        <img class="ad-slot__pixel"
             src="{{ $slot['impressionUrl'] }}"
             alt=""
             aria-hidden="true"
             width="1"
             height="1">
    </div>
@endif