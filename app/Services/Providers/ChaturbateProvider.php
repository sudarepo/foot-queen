<?php

namespace App\Services\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChaturbateProvider implements CamProviderInterface
{
    /**
     * Chaturbate Users Online v2 API endpoint.
     *
     * Required query params:
     *   wm        = your webmaster/affiliate ID ("campaign slug")
     *   client_ip = 'request_ip' (literal) or an IPv4/IPv6 address
     *
     * Optional: format, limit (1-500, default 100), offset, gender,
     *           region, tag, hd, exhibitionist
     *
     * Response shape: { "count": <int>, "results": [ {...}, ... ] }
     * Pagination is done via offset + count (there's no `next` field).
     */
    private const ENDPOINT = 'https://chaturbate.com/api/public/affiliates/onlinerooms/';

    private const PAGE_LIMIT = 500;   // API max

    public function getName(): string
    {
        return 'chaturbate';
    }

    public function fetchCams(): array
    {
        $wm = config('cam-providers.chaturbate.affiliate_id');
        if (empty($wm)) {
            Log::warning('Chaturbate affiliate_id is not set; skipping fetch.');
            return [];
        }

        $campaign = config('cam-providers.chaturbate.campaign', 'default');

        $all = [];
        $offset = 0;
        $total = null;

        // Paginate: the v2 API returns `count` (total matching) and `results`.
        // There's no `next` field — we just increment offset until we've seen `count` rooms.
        while (true) {
            $response = Http::timeout(30)->get(self::ENDPOINT, [
                'wm'        => $wm,
                'client_ip' => 'request_ip',
                'format'    => 'json',
                'limit'     => self::PAGE_LIMIT,
                'offset'    => $offset,
            ]);

            if ($response->failed()) {
                Log::error('Chaturbate API failed', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                break;
            }

            $data = $response->json();
            $rooms = $data['results'] ?? [];
            $total = $total ?? ($data['count'] ?? 0);

            if (empty($rooms)) {
                break;
            }

            foreach ($rooms as $room) {
                $normalized = $this->normalize($room, $wm, $campaign);
                if ($normalized !== null) {
                    $all[] = $normalized;
                }
            }

            $offset += count($rooms);

            // Stop when we've fetched everything the API says exists.
            if ($offset >= $total) {
                break;
            }

            // Safety cap — don't loop forever if the API misbehaves.
            if ($offset > 20000) {
                Log::warning('Chaturbate pagination safety cap hit at offset '.$offset);
                break;
            }
        }

        return $all;
    }

    /**
     * Convert a raw Chaturbate room into our normalized shape.
     * Returns null for rooms we don't want to show (e.g. private/away shows).
     */
    private function normalize(array $room, string $wm, string $campaign): ?array
    {
        if (empty($room['username'])) {
            return null;
        }

        // Only surface rooms that viewers can actually enter.
        // "private", "group", and "away" shows aren't useful on an aggregator grid.
        $currentShow = $room['current_show'] ?? 'public';
        if ($currentShow !== 'public') {
            return null;
        }

        $taxonomy = config('cam-taxonomy');
        $tags = array_map('strtolower', $room['tags'] ?? []);

        $gender = $taxonomy['gender'][$room['gender'] ?? ''] ?? 'female';

        // Hair color: first matching tag wins.
        $hair = null;
        foreach ($tags as $tag) {
            if (isset($taxonomy['hair_color'][$tag])) {
                $hair = $taxonomy['hair_color'][$tag];
                break;
            }
        }

        // Body type: first matching tag wins.
        $body = null;
        foreach ($tags as $tag) {
            if (isset($taxonomy['body_type'][$tag])) {
                $body = $taxonomy['body_type'][$tag];
                break;
            }
        }

        // Categories: intersect tags with our featured list.
        $categories = array_values(array_intersect($tags, $taxonomy['featured_categories']));

        // Build the outbound room URL with our affiliate tracking.
        // Chaturbate's `chat_room_url_revshare` field already contains our wm+track
        // when we sent the `wm` param in the feed request. Fallback only if absent.
        $roomUrl = $room['chat_room_url_revshare']
            ?? $room['chat_room_url']
            ?? "https://chaturbate.com/in/?tour=dT8X&campaign={$wm}&track={$campaign}&room={$room['username']}";

        // Prefer the smaller, faster-loading thumbnail for the grid.
        // Fall back to the full-size image_url if the 360x270 variant isn't present.
        $thumb = $room['image_url_360x270'] ?? $room['image_url'] ?? null;

        return [
            'provider'         => $this->getName(),
            'external_id'      => $room['username'],
            'username'         => $room['username'],
            'gender'           => $gender,
            'age'              => isset($room['age']) ? (int) $room['age'] : null,
            'hair_color'       => $hair,
            'body_type'        => $body,
            'categories'       => $categories,
            'viewers'          => (int) ($room['num_users'] ?? 0),
            'thumbnail_url'    => $thumb,
            'room_url'         => $roomUrl,
            'room_subject'     => $room['room_subject'] ?? null,
            'country'          => $room['country'] ?? null,
            'spoken_languages' => $room['spoken_languages'] ?? null,
            'is_hd'            => (bool) ($room['is_hd'] ?? false),
            'is_new'           => (bool) ($room['is_new'] ?? false),
            'is_online'        => true,
        ];
    }
}
