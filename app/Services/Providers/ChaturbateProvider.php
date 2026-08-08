<?php

namespace App\Services\Providers;

use App\Models\Site;
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

    /**
     * Search targets used when no site defines any — a fresh install, or one
     * where every site has been deactivated. These are the tags Foot Queen
     * ran on before sites were configurable.
     *
     * 'feet' alone already captures the vast majority of relevant content.
     * Checked empirically against the live API (2026-07-26, ~420 online
     * 'feet' results at the time): 'soles' added zero performers not already
     * covered by 'feet'; 'footfetish' and 'toes' each added only ~2;
     * 'feetworship' and 'pedicure' returned zero results. Included the two
     * that showed any signal; skipped the two that showed none.
     */
    private const FALLBACK_TAGS = ['feet', 'footfetish', 'toes'];

    private const FALLBACK_GENDER = 'f';

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

        // Keyed by username so a performer surfacing under more than one tag
        // — or on more than one site — isn't processed twice. This is the
        // whole point of syncing the union: the shared pool holds one row
        // per performer no matter how many domains show them.
        $byUsername = [];

        foreach ($this->searchTargets() as $target) {
            foreach ($this->fetchTarget($target, $wm, $campaign) as $cam) {
                $byUsername[$cam['external_id']] = $cam;
            }
        }

        return array_values($byUsername);
    }

    /**
     * Every (gender, tag) pair any active site needs, deduplicated.
     *
     * Two sites asking for the same tag cost one fetch, not two — and a
     * deactivated site stops costing anything at all.
     *
     * @return array<int, array{gender: ?string, tag: ?string}>
     */
    private function searchTargets(): array
    {
        $genderCodes = array_flip(config('cam-taxonomy.gender', []));
        $targets = [];

        foreach (Site::query()->where('is_active', true)->get() as $site) {
            $gender = filled($site->gender) ? ($genderCodes[$site->gender] ?? null) : null;

            // A site with no tags is defined by gender alone, which is still
            // a valid search — one request with no `tag` param.
            $tags = $site->tags ?: [null];

            foreach ($tags as $tag) {
                $targets[$gender.'|'.$tag] = ['gender' => $gender, 'tag' => $tag];
            }
        }

        if ($targets === []) {
            foreach (self::FALLBACK_TAGS as $tag) {
                $targets[self::FALLBACK_GENDER.'|'.$tag] = ['gender' => self::FALLBACK_GENDER, 'tag' => $tag];
            }
        }

        return array_values($targets);
    }

    /**
     * Paginate through every online room matching one search target.
     *
     * @param  array{gender: ?string, tag: ?string}  $target
     * @return array<int, array<string, mixed>>
     */
    private function fetchTarget(array $target, string $wm, string $campaign): array
    {
        $tag = $target['tag'];
        $all = [];
        $offset = 0;
        $total = null;

        // Paginate: the v2 API returns `count` (total matching) and `results`.
        // There's no `next` field — we just increment offset until we've seen `count` rooms.
        while (true) {
            // Omit `gender`/`tag` rather than sending them empty — the API
            // rejects a blank tag outright instead of treating it as "any".
            $response = Http::timeout(30)->get(self::ENDPOINT, array_filter([
                'wm' => $wm,
                'client_ip' => 'request_ip',
                'format' => 'json',
                'limit' => self::PAGE_LIMIT,
                'offset' => $offset,
                'gender' => $target['gender'],
                'tag' => $tag,
            ], fn ($value) => $value !== null));

            if ($response->failed()) {
                Log::error('Chaturbate API failed', [
                    'gender' => $target['gender'],
                    'tag' => $tag,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
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
                Log::warning('Chaturbate pagination safety cap hit', [
                    'tag' => $tag,
                    'offset' => $offset,
                ]);
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

        // Categories: intersect tags with our featured list. The raw tags are
        // kept alongside them — that's what Cam::scopeForSite() filters on, so
        // a site's niche doesn't have to be in the featured list to work.
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
            'provider' => $this->getName(),
            'external_id' => $room['username'],
            'username' => $room['username'],
            'gender' => $gender,
            'age' => isset($room['age']) ? (int) $room['age'] : null,
            'hair_color' => $hair,
            'body_type' => $body,
            'categories' => $categories,
            'tags' => array_values($tags),
            'viewers' => (int) ($room['num_users'] ?? 0),
            'thumbnail_url' => $thumb,
            'room_url' => $roomUrl,
            'embed_url' => $this->extractEmbedUrl($room),
            'room_subject' => $room['room_subject'] ?? null,
            'country' => $room['country'] ?? null,
            'spoken_languages' => $room['spoken_languages'] ?? null,
            'is_hd' => (bool) ($room['is_hd'] ?? false),
            'is_new' => (bool) ($room['is_new'] ?? false),
            'is_online' => true,
        ];
    }

    /**
     * Chaturbate's API returns ready-made `<iframe>` embed snippets for
     * affiliates — `iframe_embed_revshare` is the revenue-share-tracked one,
     * meant for exactly this: embedding the live room on our own pages.
     *
     * That snippet's `src` points at `/in/?tour=...&room=...`, a top-level
     * "tour" redirect meant for outbound links: it 302s through
     * `/gotoroom/embed/` before finally landing on `/embed/{username}/`,
     * the actual video-only page. Two cross-origin redirects inside an
     * iframe is exactly what ad blockers and browser privacy modes tend to
     * kill. We keep the same tracking query params (tour/campaign/track)
     * but target `/embed/{username}/` directly.
     *
     * We hit it via cbxyz.com rather than chaturbate.com: it's the same
     * Chaturbate-owned endpoint (whitelisted in chaturbate.com's own CSP
     * frame-src) one single 302 away from `chaturbate.com/embed/...`, but a
     * domain-based ad-block rule matching "chaturbate.com" in the initial
     * iframe src won't catch it. Also force `disable_sound=1` so hover
     * previews don't autoplay audio.
     */
    private function extractEmbedUrl(array $room): ?string
    {
        $html = $room['iframe_embed_revshare'] ?? $room['iframe_embed'] ?? null;
        if (empty($html) || ! preg_match('/src=[\'"]([^\'"]+)[\'"]/', $html, $matches)) {
            return null;
        }

        $url = html_entity_decode($matches[1]);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['disable_sound'] = 1;

        $username = $query['room'] ?? $room['username'] ?? null;
        if (empty($username)) {
            return null;
        }

        return "https://cbxyz.com/embed/{$username}/?".http_build_query($query);
    }
}
