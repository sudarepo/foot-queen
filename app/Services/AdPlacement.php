<?php

namespace App\Services;

/**
 * The two banner placements Greg asked for in phase 1.
 */
enum AdPlacement: string
{
    case HomepageHeader = 'homepage_header';

    case VideoPageHeader = 'video_page_header';

    public function label(): string
    {
        return match ($this) {
            self::HomepageHeader => 'Homepage header',
            self::VideoPageHeader => 'Video page header',
        };
    }

    /**
     * @return array<string, string> value => label for form selects.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $placement): array => [$placement->value => $placement->label()])
            ->all();
    }
}
