<?php

/**
 * SEO Landing Pages Registry
 * ===========================
 *
 * Every preset landing page lives here. This is the ONLY file you edit to add,
 * remove, or rename SEO pages. The router, the controller, the sitemap generator,
 * and the breadcrumb builder all read from this file.
 *
 * Each entry's key is the slug path (what appears after the domain).
 *
 * Keys per entry:
 *   filters     — array passed to Cam::scopeFilter()
 *   h1          — main heading on the page
 *   title       — <title> tag (appended with site name)
 *   meta        — <meta name="description">
 *   canonical   — OPTIONAL slug of the canonical version if this is a duplicate.
 *                 Leave null/unset if this IS the canonical URL.
 *   priority    — sitemap priority 0.0–1.0 (default 0.7)
 *   changefreq  — sitemap changefreq (default 'daily')
 */

return [

    /* ====================================================================
     *  Top-level gender pages (highest-priority, primary canonical URLs)
     * ==================================================================== */

    'girls' => [
        'filters'    => ['gender' => 'female'],
        'h1'         => 'Live Cam Girls',
        'title'      => 'Live Cam Girls — Watch Free Webcams Now',
        'meta'       => 'Thousands of cam girls broadcasting live right now. Filter by age, hair color, body type, and more.',
        'priority'   => 1.0,
    ],

    'guys' => [
        'filters'    => ['gender' => 'male'],
        'h1'         => 'Live Cam Guys',
        'title'      => 'Live Cam Guys — Male Webcams Online Now',
        'meta'       => 'Male performers broadcasting live. Browse hundreds of cam guys online and filter by age, body type, and more.',
        'priority'   => 0.9,
    ],

    'trans' => [
        'filters'    => ['gender' => 'trans'],
        'h1'         => 'Live Trans Cams',
        'title'      => 'Live Trans Cams — Transgender Webcams Online',
        'meta'       => 'Trans performers broadcasting live. Browse trans cams and filter by age, body type, and preferences.',
        'priority'   => 0.9,
    ],

    'couples' => [
        'filters'    => ['gender' => 'couple'],
        'h1'         => 'Live Couple Cams',
        'title'      => 'Live Couple Cams — Watch Couples Broadcasting Now',
        'meta'       => 'Couples broadcasting live right now. Watch authentic couple cams and filter by your preferences.',
        'priority'   => 0.9,
    ],

    /* ====================================================================
     *  Hair color — female (canonical) + modifier-only (alias to canonical)
     * ==================================================================== */

    'girls/blonde' => [
        'filters' => ['gender' => 'female', 'hair_color' => 'blonde'],
        'h1'      => 'Blonde Cam Girls',
        'title'   => 'Blonde Cam Girls — Live Blonde Webcams',
        'meta'    => 'Live blonde cam girls broadcasting now. Watch blondes on webcam with real-time streaming.',
    ],
    'blonde' => [
        'filters'   => ['gender' => 'female', 'hair_color' => 'blonde'],
        'h1'        => 'Blonde Cam Girls',
        'title'     => 'Blonde Cams — Live Blondes Online',
        'meta'      => 'Live blonde cams streaming right now.',
        'canonical' => 'girls/blonde',
    ],

    'girls/brunette' => [
        'filters' => ['gender' => 'female', 'hair_color' => 'brunette'],
        'h1'      => 'Brunette Cam Girls',
        'title'   => 'Brunette Cam Girls — Live Brunette Webcams',
        'meta'    => 'Live brunette cam girls broadcasting now. Watch brunettes on webcam with real-time streaming.',
    ],
    'brunette' => [
        'filters'   => ['gender' => 'female', 'hair_color' => 'brunette'],
        'h1'        => 'Brunette Cam Girls',
        'title'     => 'Brunette Cams — Live Brunettes Online',
        'meta'      => 'Live brunette cams streaming right now.',
        'canonical' => 'girls/brunette',
    ],

    'girls/redhead' => [
        'filters' => ['gender' => 'female', 'hair_color' => 'red'],
        'h1'      => 'Redhead Cam Girls',
        'title'   => 'Redhead Cam Girls — Live Red-Haired Webcams',
        'meta'    => 'Live redhead cam girls broadcasting now. Watch redheads on webcam.',
    ],
    'redhead' => [
        'filters'   => ['gender' => 'female', 'hair_color' => 'red'],
        'h1'        => 'Redhead Cam Girls',
        'title'     => 'Redhead Cams — Live Redheads Online',
        'meta'      => 'Live redhead cams streaming right now.',
        'canonical' => 'girls/redhead',
    ],

    'girls/black-hair' => [
        'filters' => ['gender' => 'female', 'hair_color' => 'black'],
        'h1'      => 'Black-Haired Cam Girls',
        'title'   => 'Black-Haired Cam Girls — Live Webcams',
        'meta'    => 'Live black-haired cam girls broadcasting now.',
    ],

    /* ====================================================================
     *  Body type
     * ==================================================================== */

    'girls/petite' => [
        'filters' => ['gender' => 'female', 'body_type' => 'slim'],
        'h1'      => 'Petite Cam Girls',
        'title'   => 'Petite Cam Girls — Live Slim Webcams',
        'meta'    => 'Live petite and slim cam girls broadcasting now. Watch slim-figured performers.',
    ],
    'petite' => [
        'filters'   => ['gender' => 'female', 'body_type' => 'slim'],
        'h1'        => 'Petite Cam Girls',
        'title'     => 'Petite Cams — Slim Webcams Online',
        'meta'      => 'Live petite cams streaming now.',
        'canonical' => 'girls/petite',
    ],

    'girls/fit' => [
        'filters' => ['gender' => 'female', 'body_type' => 'athletic'],
        'h1'      => 'Fit & Athletic Cam Girls',
        'title'   => 'Fit Cam Girls — Athletic Webcams Live',
        'meta'    => 'Live athletic and fit cam girls broadcasting now.',
    ],
    'fit' => [
        'filters'   => ['gender' => 'female', 'body_type' => 'athletic'],
        'h1'        => 'Fit & Athletic Cam Girls',
        'title'     => 'Fit Cams — Athletic Webcams Online',
        'meta'      => 'Live athletic cams streaming now.',
        'canonical' => 'girls/fit',
    ],

    'girls/curvy' => [
        'filters' => ['gender' => 'female', 'body_type' => 'curvy'],
        'h1'      => 'Curvy Cam Girls',
        'title'   => 'Curvy Cam Girls — Live Curvy Webcams',
        'meta'    => 'Live curvy cam girls broadcasting now. Watch curvy performers live.',
    ],
    'curvy' => [
        'filters'   => ['gender' => 'female', 'body_type' => 'curvy'],
        'h1'        => 'Curvy Cam Girls',
        'title'     => 'Curvy Cams — Live Online',
        'meta'      => 'Live curvy cams streaming now.',
        'canonical' => 'girls/curvy',
    ],

    'girls/bbw' => [
        'filters' => ['gender' => 'female', 'body_type' => 'bbw'],
        'h1'      => 'BBW Cam Girls',
        'title'   => 'BBW Cam Girls — Live BBW Webcams',
        'meta'    => 'Live BBW cam girls broadcasting now. Watch big beautiful women on webcam.',
    ],
    'bbw' => [
        'filters'   => ['gender' => 'female', 'body_type' => 'bbw'],
        'h1'        => 'BBW Cams',
        'title'     => 'BBW Cams — Live Webcams Online',
        'meta'      => 'Live BBW cams streaming now.',
        'canonical' => 'girls/bbw',
    ],

    /* ====================================================================
     *  Age bands
     * ==================================================================== */

    'girls/teen' => [
        'filters' => ['gender' => 'female', 'age_range' => '18-22'],
        'h1'      => 'Teen (18+) Cam Girls',
        'title'   => 'Teen Cam Girls (18-22) — Live Young Webcams',
        'meta'    => 'Live cam girls aged 18-22 broadcasting now. All performers 18+.',
    ],
    'teen' => [
        'filters'   => ['gender' => 'female', 'age_range' => '18-22'],
        'h1'        => 'Teen (18+) Cam Girls',
        'title'     => 'Teen Cams (18+) — Live Webcams',
        'meta'      => 'Live 18+ teen cams streaming now.',
        'canonical' => 'girls/teen',
    ],

    'girls/twenties' => [
        'filters' => ['gender' => 'female', 'age_range' => '23-29'],
        'h1'      => 'Cam Girls in their Twenties',
        'title'   => 'Cam Girls 23-29 — Live Webcams',
        'meta'    => 'Live cam girls in their twenties broadcasting right now.',
    ],

    'girls/milf' => [
        'filters' => ['gender' => 'female', 'age_range' => '30-39'],
        'h1'      => 'MILF Cams',
        'title'   => 'MILF Cams — Live 30+ Webcams',
        'meta'    => 'Live MILF cams broadcasting right now. Watch women 30-39 on webcam.',
    ],
    'milf' => [
        'filters'   => ['gender' => 'female', 'age_range' => '30-39'],
        'h1'        => 'MILF Cams',
        'title'     => 'MILF Cams — Live Online',
        'meta'      => 'Live MILF cams streaming now.',
        'canonical' => 'girls/milf',
    ],

    'girls/mature' => [
        'filters' => ['gender' => 'female', 'age_range' => '40-49'],
        'h1'      => 'Mature Cam Girls',
        'title'   => 'Mature Cam Girls (40+) — Live Webcams',
        'meta'    => 'Live mature cam girls broadcasting now. Watch women 40+ on webcam.',
    ],
    'mature' => [
        'filters'   => ['gender' => 'female', 'age_range' => '40-49'],
        'h1'        => 'Mature Cam Girls',
        'title'     => 'Mature Cams — 40+ Webcams',
        'meta'      => 'Live mature cams streaming now.',
        'canonical' => 'girls/mature',
    ],

    /* ====================================================================
     *  Category (tag-based) pages — the most valuable for long-tail SEO
     * ==================================================================== */

    'girls/latina' => [
        'filters' => ['gender' => 'female', 'category' => 'latina'],
        'h1'      => 'Latina Cam Girls',
        'title'   => 'Latina Cam Girls — Live Latin Webcams',
        'meta'    => 'Live Latina cam girls broadcasting now. Watch Latin performers on webcam.',
    ],
    'latina' => [
        'filters'   => ['gender' => 'female', 'category' => 'latina'],
        'h1'        => 'Latina Cams',
        'title'     => 'Latina Cams — Live Online',
        'meta'      => 'Live Latina cams streaming now.',
        'canonical' => 'girls/latina',
    ],

    'girls/asian' => [
        'filters' => ['gender' => 'female', 'category' => 'asian'],
        'h1'      => 'Asian Cam Girls',
        'title'   => 'Asian Cam Girls — Live Asian Webcams',
        'meta'    => 'Live Asian cam girls broadcasting now. Watch Asian performers on webcam.',
    ],
    'asian' => [
        'filters'   => ['gender' => 'female', 'category' => 'asian'],
        'h1'        => 'Asian Cams',
        'title'     => 'Asian Cams — Live Online',
        'meta'      => 'Live Asian cams streaming now.',
        'canonical' => 'girls/asian',
    ],

    'girls/ebony' => [
        'filters' => ['gender' => 'female', 'category' => 'ebony'],
        'h1'      => 'Ebony Cam Girls',
        'title'   => 'Ebony Cam Girls — Live Ebony Webcams',
        'meta'    => 'Live ebony cam girls broadcasting now.',
    ],
    'ebony' => [
        'filters'   => ['gender' => 'female', 'category' => 'ebony'],
        'h1'        => 'Ebony Cams',
        'title'     => 'Ebony Cams — Live Online',
        'meta'      => 'Live ebony cams streaming now.',
        'canonical' => 'girls/ebony',
    ],

    'girls/squirt' => [
        'filters' => ['gender' => 'female', 'category' => 'squirt'],
        'h1'      => 'Squirting Cam Girls',
        'title'   => 'Squirt Cams — Live Squirting Webcams',
        'meta'    => 'Live squirting cam girls broadcasting now.',
    ],

    'girls/lovense' => [
        'filters' => ['gender' => 'female', 'category' => 'lovense'],
        'h1'      => 'Lovense Cam Girls',
        'title'   => 'Lovense Cams — Interactive Toy Webcams',
        'meta'    => 'Live cam girls using Lovense interactive toys. Tip to control.',
    ],

    'girls/anal' => [
        'filters' => ['gender' => 'female', 'category' => 'anal'],
        'h1'      => 'Anal Cam Girls',
        'title'   => 'Anal Cam Girls — Live Webcams',
        'meta'    => 'Live anal cam girls broadcasting now.',
    ],

    'girls/bigboobs' => [
        'filters' => ['gender' => 'female', 'category' => 'bigboobs'],
        'h1'      => 'Big Boobs Cam Girls',
        'title'   => 'Big Boobs Cam Girls — Live Webcams',
        'meta'    => 'Live big boobs cam girls broadcasting now.',
    ],
    'big-tits' => [
        'filters'   => ['gender' => 'female', 'category' => 'bigboobs'],
        'h1'        => 'Big Tits Cams',
        'title'     => 'Big Tits Cams — Live Online',
        'meta'      => 'Live big tits cams streaming now.',
        'canonical' => 'girls/bigboobs',
    ],

    'girls/smalltits' => [
        'filters' => ['gender' => 'female', 'category' => 'smalltits'],
        'h1'      => 'Small Tits Cam Girls',
        'title'   => 'Small Tits Cam Girls — Live Webcams',
        'meta'    => 'Live small tits cam girls broadcasting now.',
    ],

    'girls/tattoo' => [
        'filters' => ['gender' => 'female', 'category' => 'tattoo'],
        'h1'      => 'Tattooed Cam Girls',
        'title'   => 'Tattooed Cam Girls — Live Ink Webcams',
        'meta'    => 'Live tattooed cam girls broadcasting now.',
    ],

    'girls/feet' => [
        'filters' => ['gender' => 'female', 'category' => 'feet'],
        'h1'      => 'Feet Cam Girls',
        'title'   => 'Feet Cams — Live Foot Webcams',
        'meta'    => 'Live feet cam girls broadcasting now.',
    ],

    'girls/bdsm' => [
        'filters' => ['gender' => 'female', 'category' => 'bdsm'],
        'h1'      => 'BDSM Cam Girls',
        'title'   => 'BDSM Cams — Live Fetish Webcams',
        'meta'    => 'Live BDSM and fetish cam girls broadcasting now.',
    ],

    /* ====================================================================
     *  HD-only landing page
     * ==================================================================== */

    'hd' => [
        'filters'  => ['hd' => true],
        'h1'       => 'HD Cams',
        'title'    => 'HD Cams — Live High-Definition Webcams',
        'meta'     => 'Live cams in HD quality. Watch high-definition webcam streams only.',
        'priority' => 0.8,
    ],

];
