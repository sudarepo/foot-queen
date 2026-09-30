{{--
    Default 2257 statement, shared by every site until one overrides it.

    Available: $site, $siteName, $domain, $contactEmail (see LegalPageResolver).
    Starts at <h2> — the page's <h1> is the page title, rendered by the layout.

    The posture asserted here is the one this codebase actually implements:
    nothing is produced or hosted here, every stream and image comes from
    Chaturbate and is embedded or linked. If that ever stops being true, this
    file is the first thing that has to change.
--}}
<p>
    <strong>{{ $siteName }}</strong> ({{ $domain }}) is not a producer — primary or
    secondary, as those terms are defined in 18 U.S.C. § 2257 and 28 C.F.R. Part 75 — of
    any of the visual content that appears on this website.
</p>

<h2>What this website is</h2>

<p>
    {{ $domain }} is an index and directory of live webcam broadcasts and promotional
    material published by third parties. All live streams, images, thumbnails, video
    previews and performer profile text displayed here originate from
    <a href="https://chaturbate.com/" target="_blank" rel="noopener nofollow">Chaturbate</a>
    and are embedded from, or linked to, that platform through its affiliate programme.
    Nothing is filmed, produced, uploaded, edited or stored by the operator of this
    website.
</p>

<h2>Exemption statement</h2>

<p>
    With respect to all content appearing on {{ $domain }}, the operator's activities are
    limited to the transmission, storage, retrieval, hosting, formatting, indexing and
    translation of communications made by others, without selecting or altering the
    content of those communications. The operator therefore qualifies for the exemption
    set out at 28 C.F.R. § 75.1(c)(4) and is not required to maintain records under
    18 U.S.C. § 2257 or § 2257A for that content.
</p>

<p>
    Records required by 18 U.S.C. § 2257 and § 2257A for the content displayed on this
    website are maintained by the original producers of that content, and by the platform
    on which it is published. See
    <a href="https://chaturbate.com/legal/2257/" target="_blank" rel="noopener nofollow">Chaturbate's own 18 U.S.C. § 2257 statement</a>
    for their custodian of records.
</p>

<h2>Age verification</h2>

<p>
    Every performer whose broadcast is indexed here is verified as being 18 years of age
    or older by the source platform before that platform permits them to broadcast. This
    website does not accept user uploads of any kind, so no content reaches it that has
    not already passed that verification.
</p>

<h2>Reporting a concern</h2>

<p>
    If you believe any content reachable from {{ $domain }} depicts a person under the age
    of 18, or that it appears without the consent of a person shown in it, report it to us
    at <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> with the page address
    and a description of the content. Reports of this kind are treated as urgent: we
    remove the material from this website on receipt, without waiting to determine whether
    the report is well founded, and escalate it to the source platform, which is able to
    remove it at source and to act on the account behind it.
</p>
