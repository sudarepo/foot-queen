<?php

namespace App\Services\Providers;

/**
 * Every cam network (Chaturbate, Stripchat, etc.) implements this.
 * Returns an array of NORMALIZED cam data, ready to upsert into the DB.
 *
 * Each returned item must be an associative array with these keys:
 *  - provider         string   e.g. 'chaturbate'
 *  - external_id      string   unique ID within the provider (usually username)
 *  - username         string   display name
 *  - gender           string   'female' | 'male' | 'trans' | 'couple'
 *  - age              int|null
 *  - hair_color       string|null   'blonde' | 'brunette' | 'black' | 'red' | 'other'
 *  - body_type        string|null   'slim' | 'athletic' | 'average' | 'curvy' | 'bbw'
 *  - categories       array    free-form tags, e.g. ['lovense', 'bigboobs']
 *  - viewers          int
 *  - thumbnail_url    string
 *  - room_url         string   outbound URL with YOUR affiliate tracking
 *  - room_subject     string|null   the room title/description
 *  - country          string|null   ISO alpha-2 country code (when public)
 *  - spoken_languages string|null   e.g. "English, Spanish"
 *  - is_hd            bool
 *  - is_new           bool
 *  - is_online        bool
 */
interface CamProviderInterface
{
    public function getName(): string;

    /**
     * Fetch and return normalized cam records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCams(): array;
}
