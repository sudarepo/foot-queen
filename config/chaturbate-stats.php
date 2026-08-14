<?php

/**
 * Chaturbate's affiliate revenue Stats API — a separate credential from
 * config/cam-providers.php's room-browsing affiliate_id/campaign. This
 * account-level username+token pair reports earnings, not live rooms.
 */
return [
    'username' => env('CHATURBATE_STATS_USERNAME'),
    'token' => env('CHATURBATE_STATS_TOKEN'),
];
