<?php

namespace App\Mcp\Support;

use App\Models\Site;

/**
 * Resolves the "site" argument the tools take — an id or a slug, since an
 * agent will usually know a site by name long before it knows its key.
 */
class SiteLookup
{
    public static function find(mixed $site, bool $withTrashed = false): ?Site
    {
        if (blank($site)) {
            return null;
        }

        $query = Site::query()->when($withTrashed, fn ($query) => $query->withTrashed());

        if (is_int($site) || ctype_digit((string) $site)) {
            return (clone $query)->find((int) $site) ?? (clone $query)->where('slug', (string) $site)->first();
        }

        return $query->where('slug', (string) $site)->first();
    }
}
