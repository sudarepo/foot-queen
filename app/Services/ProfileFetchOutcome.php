<?php

namespace App\Services;

/**
 * Why a profile fetch ended the way it did.
 *
 * `refresh()` only needs "did it work", but the backfill loop has to tell
 * "this performer has no profile" from "Chaturbate is refusing us right
 * now" — the first is worth moving past, the second means every further
 * request in the batch is wasted. See CamProfileService::refreshStale().
 */
enum ProfileFetchOutcome
{
    case Updated;

    /** Deleted or renamed performer. Stamped so it isn't retried on every view. */
    case NotFound;

    /** HTTP 429, or our own rate limiter declining to make the call. */
    case Throttled;

    /** Timeout, connection error, 5xx — retry on the next pass. */
    case Failed;

    /** Not a Chaturbate cam; no endpoint to ask. */
    case Unsupported;

    public function isSuccessful(): bool
    {
        return $this === self::Updated;
    }
}
