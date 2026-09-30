{{--
    Default DMCA notice-and-takedown policy.
    Available: $site, $siteName, $domain, $contactEmail.

    The six elements of a valid notice are quoted from 17 U.S.C. § 512(c)(3) and
    the counter-notice from § 512(g)(3); keep them intact if this text is
    edited, since a policy missing them is not a safe-harbour policy.
--}}
<p>
    {{ $siteName }} ({{ $domain }}) respects the intellectual property rights of others
    and responds to notices of claimed infringement under the Digital Millennium Copyright
    Act, 17 U.S.C. § 512.
</p>

<h2>Before you send a notice</h2>

<p>
    This website does not host the material it displays. Live streams, thumbnails, images
    and profile text come from
    <a href="https://chaturbate.com/" target="_blank" rel="noopener nofollow">Chaturbate</a>
    and are embedded from, or linked to, that platform. Removing something here removes it
    from this website only; the material itself stays online until the platform hosting it
    acts. If your goal is to have the material taken off the internet, send your notice to
    that platform's designated agent as well as to us. We will act on ours either way.
</p>

<h2>Sending a notice of claimed infringement</h2>

<p>
    Send written notice to <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>,
    with "DMCA Notice" in the subject line. To be effective under 17 U.S.C. § 512(c)(3),
    your notice must include substantially all of the following:
</p>

<ul>
    <li>A physical or electronic signature of a person authorised to act on behalf of the
        owner of an exclusive right that is allegedly infringed.</li>
    <li>Identification of the copyrighted work claimed to have been infringed, or, if
        multiple works at this website are covered by a single notice, a representative
        list of those works.</li>
    <li>Identification of the material that is claimed to be infringing and that is to be
        removed, and information reasonably sufficient to permit us to locate it — for
        this website, the full address of each page it appears on.</li>
    <li>Information reasonably sufficient to permit us to contact you: an address,
        telephone number and, if available, an email address.</li>
    <li>A statement that you have a good faith belief that use of the material in the
        manner complained of is not authorised by the copyright owner, its agent, or the
        law.</li>
    <li>A statement that the information in the notice is accurate, and under penalty of
        perjury, that you are authorised to act on behalf of the owner of an exclusive
        right that is allegedly infringed.</li>
</ul>

<p>
    A notice missing these elements may not be effective, and we may come back to you for
    what is missing rather than act on it.
</p>

<h2>What we do with it</h2>

<p>
    On receipt of an effective notice we expeditiously remove or disable access to the
    material identified, and we notify the source platform so that it can consider the
    claim against the material it hosts. We keep a record of every notice received and of
    what was done about it.
</p>

<h2>Counter-notification</h2>

<p>
    If material of yours was removed from this website and you believe that was a mistake,
    or that the material is not infringing, you may send a counter-notification to
    <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. Under 17 U.S.C. §
    512(g)(3) it must include:
</p>

<ul>
    <li>Your physical or electronic signature.</li>
    <li>Identification of the material that was removed and the location at which it
        appeared before it was removed.</li>
    <li>A statement under penalty of perjury that you have a good faith belief that the
        material was removed or disabled as a result of mistake or misidentification.</li>
    <li>Your name, address and telephone number, and a statement that you consent to the
        jurisdiction of the Federal District Court for the judicial district in which your
        address is located — or, if your address is outside the United States, for any
        judicial district in which we may be found — and that you will accept service of
        process from the person who gave the original notice or an agent of that
        person.</li>
</ul>

<p>
    We will forward an effective counter-notification to the party who sent the original
    notice. Unless that party notifies us that they have filed an action seeking a court
    order to restrain the allegedly infringing activity, we may restore the material in
    not less than 10 and not more than 14 business days after receiving the
    counter-notification.
</p>

<h2>Repeat infringers</h2>

<p>
    It is our policy, in appropriate circumstances, to remove listings associated with
    parties who are repeat infringers, and to report them to the source platform so that
    it may act on the account behind them.
</p>

<h2>Misrepresentation</h2>

<p>
    Under 17 U.S.C. § 512(f), any person who knowingly materially misrepresents that
    material is infringing, or that it was removed or disabled by mistake, may be liable
    for damages, including costs and legal fees. If you are unsure whether the use you are
    complaining about is infringing — fair use, for example — take legal advice before
    sending a notice.
</p>

<h2>Content you appear in</h2>

<p>
    If you are the person shown in material reachable from this website and it appears
    without your consent, you do not need a copyright claim to have it removed. Write to
    <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> describing the material
    and where it appears, and we will remove it from this website and escalate it to the
    source platform. See also our
    <a href="{{ route(\App\Services\LegalPage::Usc2257->routeName()) }}">2257 statement</a>.
</p>
