{{--
    Default terms of use. Available: $site, $siteName, $domain, $contactEmail.

    Written for what this site is: a directory that embeds and links third-party
    broadcasts and earns affiliate commission on click-throughs. No accounts, no
    uploads, no payments taken here.
--}}
<p>
    These terms govern your use of {{ $siteName }} ({{ $domain }}). By opening any page on
    this website you accept them. If you do not accept them, leave the website.
</p>

<h2>1. You must be an adult</h2>

<p>
    This website contains sexually explicit material intended for adults. You may use it
    only if you are at least 18 years old, or the age of majority in the place you are
    accessing it from if that age is higher, and only if such material is lawful where you
    are. By continuing you confirm that both are true. It is your responsibility to know
    the law that applies to you.
</p>

<p>
    Do not allow anyone under that age to access this website from your device or
    connection. Parental filtering tools such as
    <a href="https://www.asacp.org/" target="_blank" rel="noopener nofollow">ASACP</a>,
    <a href="https://www.netnanny.com/" target="_blank" rel="noopener nofollow">Net Nanny</a>
    and <a href="https://www.cybersitter.com/" target="_blank" rel="noopener nofollow">CyberSitter</a>
    can be used to restrict access to sites of this kind.
</p>

<h2>2. What this website is</h2>

<p>
    {{ $domain }} is a directory. It indexes live webcam broadcasts published on
    <a href="https://chaturbate.com/" target="_blank" rel="noopener nofollow">Chaturbate</a>
    and presents them alongside listings, categories and performer pages. Every stream,
    image and profile shown here is embedded from, or links to, that platform. We do not
    produce, film, host, upload or store any of that content, and we do not control what
    is broadcast. See our
    <a href="{{ route(\App\Services\LegalPage::Usc2257->routeName()) }}">2257 statement</a>.
</p>

<p>
    We are not affiliated with, and do not represent, any performer appearing on this
    website. Listing someone is not an endorsement by them of this website, nor by us of
    them.
</p>

<h2>3. Affiliate disclosure</h2>

<p>
    We earn a commission when a visitor follows a link from this website to the source
    platform and takes certain actions there. This costs you nothing, and it does not
    change what you pay on that platform. It is how the website is paid for.
</p>

<h2>4. No accounts and no payments here</h2>

<p>
    This website has no registration, no accounts and no shopping basket, and it never
    asks for payment or card details. Any account you create, any token you buy and any
    payment you make happens on the source platform, under that platform's own terms and
    with that platform's own support and refund process. We cannot see, alter or refund
    those transactions. Anyone asking you to pay {{ $siteName }} directly is not us.
</p>

<h2>5. Acceptable use</h2>

<p>You agree not to:</p>

<ul>
    <li>use this website in any way that breaks the law where you are, or where we are;</li>
    <li>record, capture, reproduce, redistribute or resell any broadcast, image or other
        material reachable from this website;</li>
    <li>scrape, crawl, harvest or systematically copy the website, or use it to build or
        train a dataset or model, other than by ordinary search engine indexing consistent
        with our robots file;</li>
    <li>interfere with the website's operation, probe it for vulnerabilities, or attempt
        to bypass any technical restriction on it;</li>
    <li>impersonate anyone, or misrepresent your age or identity;</li>
    <li>harass, threaten or attempt to identify, locate or contact any performer shown
        here outside the source platform.</li>
</ul>

<p>
    We may block access to this website at any time, without notice, from anyone we
    reasonably believe is breaking these rules.
</p>

<h2>6. Intellectual property</h2>

<p>
    The name, design, layout, original text and other original material of this website
    belong to its operator and may not be copied or reused without permission. Performer
    names, images and broadcasts belong to their respective owners and appear here under
    the terms of the source platform's affiliate programme. If you believe material on
    this website infringes your rights, follow our
    <a href="{{ route(\App\Services\LegalPage::Dmca->routeName()) }}">DMCA procedure</a>.
</p>

<h2>7. Links to other websites</h2>

<p>
    This website links to websites we do not run. We are not responsible for their
    content, their practices or their terms, and a link is not an endorsement. Once you
    follow one, this agreement no longer governs your visit.
</p>

<h2>8. Availability</h2>

<p>
    The website is provided as it is. We do not promise it will be available without
    interruption, that listings will be accurate or current, that any particular performer
    will be broadcasting, or that any part of it will be free of errors. We may change,
    suspend or discontinue any part of it at any time.
</p>

<h2>9. Disclaimer of warranties</h2>

<p>
    To the fullest extent permitted by law, this website and everything on it is provided
    "as is" and "as available", without warranty of any kind, express or implied,
    including any implied warranty of merchantability, fitness for a particular purpose,
    accuracy or non-infringement.
</p>

<h2>10. Limitation of liability</h2>

<p>
    To the fullest extent permitted by law, neither the operator of this website nor
    anyone working with them will be liable for any indirect, incidental, special,
    consequential or punitive damages, or for any loss of profit, data, goodwill or
    opportunity, arising out of your use of this website or of any website reached from
    it. Nothing here limits liability that cannot lawfully be limited.
</p>

<h2>11. Indemnity</h2>

<p>
    You agree to indemnify and hold harmless the operator of this website against any
    claim, loss or expense arising from your use of the website or from your breach of
    these terms.
</p>

<h2>12. Changes to these terms</h2>

<p>
    We may revise these terms as the website changes. The version published on this page
    is the one in force, and continuing to use the website after a change means you accept
    it.
</p>

<h2>13. General</h2>

<p>
    If any provision of these terms is held unenforceable, the rest continues to apply.
    Our failure to enforce a provision is not a waiver of it. These terms, together with
    our <a href="{{ route(\App\Services\LegalPage::Privacy->routeName()) }}">Privacy Policy</a>,
    are the entire agreement between you and us regarding this website, and are governed
    by the laws applicable at the operator's principal place of business.
</p>

<h2>Contact</h2>

<p>
    Questions about these terms go to
    <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
</p>
