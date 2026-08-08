<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Splits real (non-bot) homepage visitors 50/50 between the grid and feed
 * layouts, remembering the assignment via cookie so a given visitor keeps
 * seeing the same variant on repeat visits. Bots/crawlers are excluded
 * entirely — Googlebot etc. always sees the canonical grid at "/", so this
 * never affects indexing, and their traffic never pollutes the conversion
 * numbers.
 *
 * Whether a given visitor is split at all is the site's call, per device —
 * see HomepageLayout and Site::homeLayout().
 */
class HomepageAbTest
{
    public const COOKIE_NAME = 'ab_feed_variant';

    public const VARIANT_GRID = 'grid';

    public const VARIANT_FEED = 'feed';

    private const COOKIE_MINUTES = 60 * 24 * 90; // 90 days

    /**
     * The date this 50/50 split first shipped (commit aa28dbb). Page views
     * before this are almost entirely 'grid' — "/feed" had no traffic before
     * this deployed, since nothing linked to it yet. The admin conversion
     * dashboard defaults its date filter to this, so the numbers shown are
     * fair by default instead of skewed by the pre-launch period.
     */
    public const LAUNCHED_AT = '2026-07-26';

    /**
     * Loose but low-maintenance bot/crawler detection via user-agent — good
     * enough to keep search crawlers and common scrapers/monitors out of the
     * experiment without adding a dependency for it.
     */
    private const BOT_PATTERN = '/bot|spider|crawl|slurp|facebookexternalhit|whatsapp|telegrambot|slackbot|discordbot|archiver|applebot|petalbot|gptbot|claudebot|ccbot|anthropic|pingdom|uptime|monitor/i';

    public function isBot(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        return $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /**
     * Resolves the variant for this visitor, honoring an existing cookie if
     * present, otherwise assigning one at random.
     *
     * A site that has pinned this device to one layout (see HomepageLayout)
     * short-circuits all of that: the pinned variant wins over any cookie the
     * visitor is carrying, and nothing is assigned — the cookie stays a record
     * of a random assignment only, so switching the split back on later
     * resumes a clean experiment rather than one seeded by the pinned period.
     *
     * @return array{0: string, 1: bool} [variant, wasJustAssigned]
     */
    public function resolve(Request $request, HomepageLayout $layout = HomepageLayout::AbTest): array
    {
        $forced = $layout->forcedVariant();

        if ($forced !== null) {
            return [$forced, false];
        }

        $existing = $request->cookie(self::COOKIE_NAME);

        if (in_array($existing, [self::VARIANT_GRID, self::VARIANT_FEED], true)) {
            return [$existing, false];
        }

        $variant = random_int(0, 1) === 0 ? self::VARIANT_GRID : self::VARIANT_FEED;

        return [$variant, true];
    }

    public function cookieMinutes(): int
    {
        return self::COOKIE_MINUTES;
    }
}
