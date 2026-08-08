<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Which kind of screen a visitor is on, for analytics and for the `track`
 * sub-id sent to Chaturbate.
 *
 * Two buckets only — phone-shaped vs. everything else. A finer split (tablet,
 * console, TV) would divide the samples further without informing any layout
 * decision this site actually makes, and the two variants being tested are a
 * mobile-first feed and a desktop-first grid.
 */
class DeviceDetector
{
    public const MOBILE = 'mobile';

    public const DESKTOP = 'desktop';

    /**
     * The date events started carrying a device. Earlier rows have none — the
     * admin dashboard says so next to its device filter, rather than letting
     * a filtered view look like a traffic collapse.
     */
    public const TRACKING_SINCE = '2026-08-08';

    /**
     * Fallback for clients that don't send client hints (Safari, Firefox,
     * anything old). Every mainstream phone browser puts "Mobile" in its
     * user-agent; the rest of the alternatives cover browsers that don't.
     */
    private const MOBILE_PATTERN = '/mobile|iphone|ipod|android|blackberry|iemobile|opera mini|windows phone|webos|silk|kindle|fennec/i';

    /**
     * Chromium sends `Sec-CH-UA-Mobile` on every request without any
     * opt-in, and it's the browser's own answer rather than a guess at a
     * string, so it wins where present. Note it reports Android *tablets* as
     * not-mobile while the user-agent fallback would call them mobile —
     * that inconsistency is accepted rather than reconciled, since tablets
     * are a rounding error here and each source is right about the thing it
     * measures (form factor vs. phone-ness).
     */
    public function detect(Request $request): string
    {
        $hint = $request->header('Sec-CH-UA-Mobile');

        if ($hint !== null) {
            return str_contains($hint, '?1') ? self::MOBILE : self::DESKTOP;
        }

        return preg_match(self::MOBILE_PATTERN, (string) $request->userAgent()) === 1
            ? self::MOBILE
            : self::DESKTOP;
    }

    /**
     * Single-letter form used in outbound `track` values, where the label
     * also carries the site prefix and the source page and Chaturbate's
     * dashboard shows it in a narrow column.
     */
    public static function abbreviate(string $device): string
    {
        return $device === self::MOBILE ? 'm' : 'd';
    }
}
