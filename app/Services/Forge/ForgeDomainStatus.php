<?php

namespace App\Services\Forge;

/**
 * Whether a site's domains are set up in Forge, as last recorded in
 * `sites.forge_domain_status`. Kept on the row so the admin never has to ask
 * Forge just to draw the sites list.
 */
enum ForgeDomainStatus: string
{
    /** Never checked — a site from before statuses were recorded. */
    case Unknown = 'unknown';

    case Missing = 'missing';

    /** A provisioning job is queued or running. */
    case InProgress = 'in_progress';

    case Provisioned = 'provisioned';

    /** The last attempt to add a domain was refused; see `forge_error`. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Missing => 'Missing',
            self::InProgress => 'In progress',
            self::Provisioned => 'Provisioned',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Provisioned => 'success',
            self::InProgress => 'info',
            self::Missing => 'warning',
            self::Failed => 'danger',
            self::Unknown => 'gray',
        };
    }
}
