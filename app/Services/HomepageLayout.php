<?php

namespace App\Services;

/**
 * What "/" serves a real visitor on a given kind of screen.
 *
 * The grid-vs-feed split (see HomepageAbTest) is an experiment, and an
 * experiment is supposed to end: once a site knows which layout converts
 * better for it, it should be able to stop splitting its traffic and just
 * serve the winner. Sites are also free to reach different verdicts — a feed
 * is a phone-shaped thing, so a site settling on the feed for mobile while
 * still testing on desktop is the normal case, not an edge one.
 *
 * Stored per site and per device in `sites.home_layout_mobile` /
 * `home_layout_desktop`, editable in Filament.
 */
enum HomepageLayout: string
{
    /** Split traffic 50/50 and measure — the historical behaviour, and the default. */
    case AbTest = 'ab';

    case Grid = 'grid';

    case Feed = 'feed';

    public function label(): string
    {
        return match ($this) {
            self::AbTest => 'A/B test (50/50)',
            self::Grid => 'Grid only',
            self::Feed => 'Feed only',
        };
    }

    /**
     * The variant this layout always serves, or null when the choice is left
     * to the split. Values match HomepageAbTest::VARIANT_*.
     */
    public function forcedVariant(): ?string
    {
        return match ($this) {
            self::AbTest => null,
            self::Grid => HomepageAbTest::VARIANT_GRID,
            self::Feed => HomepageAbTest::VARIANT_FEED,
        };
    }

    /**
     * Whether a visitor on this layout can end up on the feed at all — true
     * for the feed itself and for the split, false only for grid-only.
     */
    public function canServeFeed(): bool
    {
        return $this !== self::Grid;
    }

    /**
     * @return array<string, string> value => label, for Filament selects
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $layout) => [$layout->value => $layout->label()])
            ->all();
    }
}
