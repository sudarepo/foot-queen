<?php

/**
 * Normalization taxonomy.
 *
 * Chaturbate (and most networks) expose hair color and body type as free-form tags,
 * not as first-class fields. We parse the tags array and map known values to our
 * unified vocabulary. Anything that doesn't match stays in the `categories` array.
 *
 * To extend: add entries to each map. Keys are lowercased tag strings; values are
 * our normalized labels.
 */

return [

    'gender' => [
        // Chaturbate feed genders: f, m, t, c
        'f' => 'female',
        'm' => 'male',
        't' => 'trans',
        'c' => 'couple',
    ],

    'hair_color' => [
        'blonde' => 'blonde',
        'blondie' => 'blonde',
        'platinum' => 'blonde',
        'brunette' => 'brunette',
        'brunnette' => 'brunette',   // common misspelling in tags
        'brownhair' => 'brunette',
        'blackhair' => 'black',
        'raven' => 'black',
        'redhead' => 'red',
        'ginger' => 'red',
        'pinkhair' => 'other',
        'bluehair' => 'other',
        'colorful' => 'other',
    ],

    'body_type' => [
        'slim' => 'slim',
        'skinny' => 'slim',
        'petite' => 'slim',
        'fit' => 'athletic',
        'athletic' => 'athletic',
        'muscular' => 'athletic',
        'toned' => 'athletic',
        'average' => 'average',
        'curvy' => 'curvy',
        'thick' => 'curvy',
        'milf' => 'curvy',      // not strictly body, but often used as proxy
        'bbw' => 'bbw',
        'chubby' => 'bbw',
        'bigboobs' => 'curvy',
    ],

    /**
     * Tags matching these filters get included as "categories" for the category dropdown.
     * Everything else is kept but not surfaced in the primary filter.
     *
     * These are raw feed tag strings — lowercase, no spaces or punctuation —
     * because normalize() intersects them against a room's `tags` array. A
     * display spelling like "big boobs" or "daddys girl" matches nothing.
     * See `category_labels` below for how they're presented to visitors.
     */
    'featured_categories' => [

        // Not on Chaturbate's published tag list but common in the live feed,
        // carried over from the original taxonomy. ('teen18' used to sit here
        // and matched zero rooms — '18' and 'teen' below are the real tags.)
        'lovense', 'domi', 'piercing',

        // Chaturbate's published tag list, in their popularity order.
        'squirt', 'bigboobs', 'anal', '18', 'teen', 'latina', 'bigass', 'new',
        'young', 'cum', 'feet', 'asian', 'natural', 'deepthroat', 'smalltits',
        'hairy', 'bigcock', 'petite', 'ahegao', 'skinny', 'ebony', 'milf',
        'c2c', 'german', 'fuckmachine', 'blonde', 'mature', 'pantyhose',
        'redhead', 'daddy', 'muscle', 'lesbian', 'shy', 'bbw', 'uncut',
        'mistress', 'cute', 'curvy', 'smoke', 'gay', 'french', 'fit', 'milk',
        'dirty', 'braces', 'latino', 'slave', 'findom', 'bdsm', 'twink',
        'tattoo', 'bbc', 'heels', 'pawg', 'master', 'pregnant', 'bigpussylips',
        'submissive', 'dirtytalk', 'saliva', 'femboy', 'femdom', 'stockings',
        'daddysgirl', 'joi', 'party', 'sph', 'cosplay', 'mommy', 'bigclit',
        'anime', 'british', 'atm', 'chubby', 'latex', 'smallcock', 'bignipples',
        'indian', 'slut', 'hairypussy', 'sissy', 'ukraine', 'strapon',
        'puffynipples', 'straight', 'office', 'bigdick', 'nasty', 'hairyarmpits',
        'italian', 'cei', 'bush', 'goth', 'nonude', 'cuckold', 'flexible',
        'arab', 'pinay',
    ],

    /**
     * Display names for categories whose tag slug doesn't read well on its own.
     *
     * Anything absent here falls back to ucfirst() on the slug, which is right
     * for the majority of them ('squirt', 'latina', 'goth'). This map only
     * carries the run-together compounds and the acronyms, where ucfirst would
     * print "Bigpussylips" or "Bbc".
     */
    'category_labels' => [
        'bigboobs' => 'Big boobs',
        'bigass' => 'Big ass',
        'smalltits' => 'Small tits',
        'bigcock' => 'Big cock',
        'bigdick' => 'Big dick',
        'smallcock' => 'Small cock',
        'bigclit' => 'Big clit',
        'bigpussylips' => 'Big pussy lips',
        'bignipples' => 'Big nipples',
        'puffynipples' => 'Puffy nipples',
        'hairypussy' => 'Hairy pussy',
        'hairyarmpits' => 'Hairy armpits',
        'fuckmachine' => 'Fuck machine',
        'dirtytalk' => 'Dirty talk',
        'daddysgirl' => "Daddy's girl",
        'strapon' => 'Strap-on',
        'nonude' => 'Non-nude',
        'milf' => 'MILF',
        'bbw' => 'BBW',
        'bbc' => 'BBC',
        'bdsm' => 'BDSM',
        'pawg' => 'PAWG',
        'joi' => 'JOI',
        'sph' => 'SPH',
        'cei' => 'CEI',
        'atm' => 'ATM',
        'c2c' => 'C2C',
    ],

];
