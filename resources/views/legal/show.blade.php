@extends('layouts.app')

@section('content')
    {{--
        `$body` is an HtmlString, already sanitised by LegalPageResolver — the
        admin-supplied override goes through Str::sanitizeHtml() there rather
        than being trusted here.
    --}}
    <article class="legal">
        <h1 class="legal__title">{{ $heading }}</h1>

        <div class="legal__body">
            {!! $body !!}
        </div>

        <nav class="legal__nav" aria-label="Legal pages">
            @foreach (\App\Services\LegalPage::all() as $other)
                @if ($other !== $page)
                    <a href="{{ route($other->routeName()) }}">{{ $other->footerLabel() }}</a>
                @endif
            @endforeach
        </nav>
    </article>
@endsection
