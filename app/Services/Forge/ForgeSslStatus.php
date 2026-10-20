<?php

namespace App\Services\Forge;

/**
 * Whether a site's domains have an installed certificate in Forge, as last
 * recorded in `sites.forge_ssl_status`.
 */
enum ForgeSslStatus: string
{
    /** Never checked — a site from before statuses were recorded. */
    case Unknown = 'unknown';

    case Missing = 'missing';

    /** Waiting its turn: queued, or waiting for the domain to be in Forge first. */
    case Queued = 'queued';

    /** Requested; Forge is still issuing or installing it. */
    case InProgress = 'in_progress';

    case Installed = 'installed';

    /**
     * Proxied through Cloudflare, with no certificate on the server yet.
     * Visitors only get HTTPS if the zone's SSL mode is "Flexible"; under
     * "Full" Cloudflare can't reach the server over HTTPS at all.
     */
    case Cloudflare = 'cloudflare';

    /** Let's Encrypt refused it — usually DNS not pointing at the server. */
    case Failed = 'failed';

    /** The server has a certificate for every domain — nothing to request. */
    public function isSecured(): bool
    {
        return $this === self::Installed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Missing => 'Missing',
            self::Queued => 'Queued',
            self::InProgress => 'In progress',
            self::Installed => 'Installed',
            self::Cloudflare => 'Cloudflare (no certificate)',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Installed => 'success',
            self::InProgress, self::Queued => 'info',
            self::Missing, self::Cloudflare => 'warning',
            self::Failed => 'danger',
            self::Unknown => 'gray',
        };
    }
}
