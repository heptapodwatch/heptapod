<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Enums\CheckPendingReason;
use Heptapod\Models\Check;

/**
 * The only writer of checks.pending_reason. A marked check projects as pending until an attempt of it finishes
 * or it can no longer run (disabled, paused, skipped). A new claim for the same recheck (lease replacement, revoke) keeps the mark.
 * Callers hold the check row lock inside their transaction.
 */
final class CheckPending
{
    /** Writes by key: the caller's model may predate the lock, so its dirty state cannot be trusted. */
    public static function mark(Check $check, CheckPendingReason $reason): void
    {
        Check::query()->whereKey($check->id)->update(['pending_reason' => $reason]);
    }

    /**
     * $check is the row read under the lock. Runs on every finished attempt, so an unmarked check costs no query.
     * A marked one writes only this column: the caller's other dirty attributes stay for its own save.
     * Returns whether a mark was cleared, so a caller that sends no state.changed of its own can announce the new site state.
     */
    public static function clear(Check $check): bool
    {
        if ($check->pending_reason === null) {
            return false;
        }
        Check::query()->whereKey($check->id)->toBase()->update(['pending_reason' => null]);
        $check->setAttribute('pending_reason', null);
        $check->syncOriginalAttribute('pending_reason');

        return true;
    }
}
