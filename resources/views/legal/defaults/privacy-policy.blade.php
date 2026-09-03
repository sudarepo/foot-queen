{{--
    Default privacy policy. Available: $site, $siteName, $domain, $contactEmail.

    Describes what this codebase actually does: an analytics cookie for the
    homepage A/B test, GA4 when the site has a measurement ID, and two internal
    event tables (page_view_events, cam_click_events). Adding any other
    tracking means editing this file as well.
--}}
<p>
    This policy explains what {{ $siteName }} ({{ $domain }}) collects when you visit,
    why it is collected, how long it is kept, and what you can ask us to do about it.
</p>

<h2>The short version</h2>

<ul>
    <li>There are no accounts on this website, and nothing here asks you to register.</li>
    <li>No payment is ever taken on this website.</li>
    <li>We do not sell or rent personal information, and we do not share it for
        cross-context behavioural advertising.</li>
    <li>What we collect is the traffic data any website receives, plus anonymous
        measurement of which pages and links people use.</li>
</ul>

<h2>What we collect</h2>

<p><strong>Information you give us.</strong> Only what you choose to put in an email to
    us. We have no contact forms, no comments, no sign-up and no newsletter.</p>

<p><strong>Information collected automatically.</strong> Like any web server, ours records
    the technical details of each request: your IP address, browser and device type, the
    page requested, the page you arrived from, and the date and time. We also record, in
    our own database, which listing pages are viewed and which performer links are
    clicked. Those records are used in aggregate to see which layouts and which pages
    work; they are not used to build a profile of you, and they are not linked to your
    identity.</p>

<h2>Cookies</h2>

<p>We use a small number of cookies:</p>

<ul>
    <li><strong>Layout preference.</strong> This website tests two versions of its
        homepage. A cookie remembers which one you were shown, so the site does not change
        shape between visits. It holds a single value and nothing about you.</li>
    <li><strong>Session.</strong> A standard cookie used to keep a browsing session
        consistent and to protect forms against cross-site request forgery.</li>
    @if (filled($site->ga_measurement_id))
        <li><strong>Analytics.</strong> Google Analytics sets cookies to measure visits,
            traffic sources and which pages are read. See
            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener nofollow">Google's privacy policy</a>
            and
            <a href="https://tools.google.com/dlpage/gaoptout" target="_blank" rel="noopener nofollow">Google's opt-out browser add-on</a>.</li>
    @endif
</ul>

<p>
    You can block or delete cookies in your browser settings. The site continues to work
    without them; only the layout preference is lost, which means the homepage may change
    shape between visits.
</p>

<h2>Why we may use it</h2>

<ul>
    <li>To serve the website and keep it working.</li>
    <li>To measure which pages, layouts and listings visitors find useful.</li>
    <li>To attribute referrals to the platform we link to, so that our referrals are
        credited to us.</li>
    <li>To detect and prevent abuse, fraud and automated scraping.</li>
    <li>To comply with legal obligations.</li>
</ul>

<p>
    Where the UK or EU General Data Protection Regulation applies, we rely on our
    legitimate interest in operating, securing and measuring the website, and on your
    consent where consent is required for analytics cookies in your jurisdiction.
</p>

<h2>Who else is involved</h2>

<p>
    We do not sell personal information. Data reaches other parties only through the
    services that make the site work:
</p>

<ul>
    <li><strong>Our hosting and infrastructure providers</strong>, which process requests
        and hold server logs on our behalf.</li>
    @if (filled($site->ga_measurement_id))
        <li><strong>Google Analytics</strong>, which measures traffic in aggregate.</li>
    @endif
    <li><strong>Chaturbate</strong>, when you click through to a broadcast. From that
        point you are on their website, under
        <a href="https://chaturbate.com/privacy/" target="_blank" rel="noopener nofollow">their privacy policy</a>,
        not this one. We pass a tracking code that identifies us as the referring site; it
        does not identify you.</li>
</ul>

<h2>Content shown from elsewhere</h2>

<p>
    Live streams, thumbnails and performer profiles on this website are embedded from
    Chaturbate. Loading them means your browser contacts that platform directly, and it
    may set its own cookies. That is outside our control and is governed by their policy.
</p>

<h2>How long we keep it</h2>

<p>
    Server logs are retained for a short operational period and then discarded. Aggregate
    measurement records are retained for as long as they remain useful for comparing
    performance over time. Email you send us is kept for as long as needed to deal with
    the matter it concerns.
</p>

<h2>Your rights</h2>

<p>
    Depending on where you live, you may have the right to ask what personal data we hold
    about you, to have it corrected or erased, to object to or restrict its processing, to
    receive a copy of it, and to withdraw consent. Residents of California have the right
    to know what is collected, to request deletion, and to opt out of the sale or sharing
    of personal information — we do neither. Exercising any of these rights will never
    result in worse treatment.
</p>

<p>
    Write to <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> to make a
    request. Because this website has no accounts, we may be unable to connect a request
    to any particular records — in which case we will say so rather than ask you for more
    identifying information than we already hold.
</p>

<h2>Adults only</h2>

<p>
    This website is intended solely for adults. It is not directed at children, and we do
    not knowingly collect any information from anyone under 18. See our
    <a href="{{ route(\App\Services\LegalPage::Terms->routeName()) }}">Terms and Conditions</a>.
</p>

<h2>Security and international transfers</h2>

<p>
    We take reasonable technical and organisational measures to protect the limited data
    we hold, though no method of transmission or storage is completely secure. Our
    providers may process data in countries other than your own, including the United
    States; where required, transfers are made under appropriate safeguards.
</p>

<h2>Changes</h2>

<p>
    We may update this policy as the website changes. The current version is always the
    one published on this page.
</p>

<h2>Contact</h2>

<p>
    Questions about this policy, or about anything on {{ $domain }}, go to
    <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
</p>
