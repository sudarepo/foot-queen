<?php

return [

    /**
     * Providers enabled for sync. Add new providers here as they're built.
     * The order doesn't matter; each is synced independently.
     */
    'enabled' => [
        \App\Services\Providers\ChaturbateProvider::class,
        // \App\Services\Providers\StripchatProvider::class,  // TODO
    ],

    'chaturbate' => [
        'affiliate_id' => env('CHATURBATE_AFFILIATE_ID'),
        'campaign'     => env('CHATURBATE_CAMPAIGN', 'default'),
    ],

    'stripchat' => [
        'affiliate_id' => env('STRIPCHAT_AFFILIATE_ID'),
    ],

];
