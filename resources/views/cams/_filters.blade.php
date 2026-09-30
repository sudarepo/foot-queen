{{--
    Filter bar, shared by the grid and the feed so both stay in step.

    Selects are bound to `$filters` — the filters the listing actually ran
    with, including the default foot category and any landing-page preset —
    not just what the visitor typed into the URL. `$userFilters` is the
    visitor's own choices, and only decides whether "Clear" is offered.
--}}
@php
    /** @var array<string, array{label: string, key: string}> query param => label + key in $filters */
    $filterFields = [
        'category' => ['label' => 'Category', 'key' => 'category'],
        'age' => ['label' => 'Age', 'key' => 'age_range'],
        'hair' => ['label' => 'Hair', 'key' => 'hair_color'],
        'body' => ['label' => 'Body', 'key' => 'body_type'],
    ];
@endphp

<section class="filters-bar">
    <form method="GET" action="{{ url()->current() }}" class="filters">
        {{-- Filters this bar doesn't render still belong to the visitor, so
             carry them through instead of dropping them on every change. --}}
        @if (!empty($userFilters['gender']))
            <input type="hidden" name="gender" value="{{ $userFilters['gender'] }}">
        @endif
        @if (!empty($userFilters['hd']))
            <input type="hidden" name="hd" value="1">
        @endif

        @foreach ($filterFields as $param => $field)
            <label class="filter">
                <span class="filter__label">{{ $field['label'] }}</span>
                <select name="{{ $param }}" aria-label="{{ $field['label'] }}" onchange="this.form.submit()">
                    {{-- Cast both sides: a purely numeric category slug such as
                         "18" comes back from the option map as an int key, and
                         a strict compare against the string filter would never
                         mark it selected. --}}
                    @foreach ($filterMeta[$param] as $value => $label)
                        <option value="{{ $value }}" @selected((string) ($filters[$field['key']] ?? '') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>
        @endforeach

        @if (!empty($userFilters))
            <a href="{{ url()->current() }}" class="filter-reset">Clear ×</a>
        @endif

        <noscript><button type="submit" class="filter-apply">Apply</button></noscript>
    </form>
</section>
