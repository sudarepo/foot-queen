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
        'blonde'     => 'blonde',
        'blondie'    => 'blonde',
        'platinum'   => 'blonde',
        'brunette'   => 'brunette',
        'brunnette'  => 'brunette',   // common misspelling in tags
        'brownhair'  => 'brunette',
        'blackhair'  => 'black',
        'raven'      => 'black',
        'redhead'    => 'red',
        'ginger'     => 'red',
        'pinkhair'   => 'other',
        'bluehair'   => 'other',
        'colorful'   => 'other',
    ],

    'body_type' => [
        'slim'       => 'slim',
        'skinny'     => 'slim',
        'petite'     => 'slim',
        'fit'        => 'athletic',
        'athletic'   => 'athletic',
        'muscular'   => 'athletic',
        'toned'      => 'athletic',
        'average'    => 'average',
        'curvy'      => 'curvy',
        'thick'      => 'curvy',
        'milf'       => 'curvy',      // not strictly body, but often used as proxy
        'bbw'        => 'bbw',
        'chubby'     => 'bbw',
        'bigboobs'   => 'curvy',
    ],

    /**
     * Tags matching these filters get included as "categories" for the category dropdown.
     * Everything else is kept but not surfaced in the primary filter.
     */
    'featured_categories' => [
        'lovense', 'anal', 'bigboobs', 'smalltits', 'squirt', 'new',
        'milf', 'teen18', 'latina', 'asian', 'ebony', 'mature',
        'tattoo', 'piercing', 'hairy', 'feet', 'bdsm', 'domi',
    ],

];
