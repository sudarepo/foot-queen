{{--
    Google Analytics 4, keyed to the site serving this request.

    Gated on production because every non-production host resolves to the
    default site — a local `php artisan serve` or a preview deploy would
    otherwise report pageviews into the live property, and a session from a
    developer refreshing a page is indistinguishable from a real visitor once
    it's in there.
--}}
@if (filled($site->ga_measurement_id) && app()->isProduction())
    <link rel="preconnect" href="https://www.googletagmanager.com">
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $site->ga_measurement_id }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', {{ Illuminate\Support\Js::from($site->ga_measurement_id) }});
    </script>
@endif
