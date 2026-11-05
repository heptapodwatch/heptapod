<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Actions\ResolveInactiveCheckIncidents;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one lock hierarchy of the notification engine. A transaction takes row locks only downwards through these levels
 * (skipping levels is fine) and several rows of one level in ascending id:
 *
 *   1. users                (users, FOR KEY SHARE: what a row naming its actor takes; profile deletion and access
 *                              changes hold FOR UPDATE)
 *   2. maintenance recurrences (recurring_maintenance_periods, FOR NO KEY UPDATE; deletion FOR UPDATE)
 *   3. maintenance windows  (maintenance_periods, FOR NO KEY UPDATE; catch-up and deletion FOR UPDATE)
 *   4. system issue         (system_issues, FOR UPDATE)
 *   5. checks               (checks, FOR KEY SHARE: what an event insert takes; the recorder holds FOR UPDATE)
 *   6. sites                (sites, FOR NO KEY UPDATE)
 *   7. incidents            (incidents, FOR NO KEY UPDATE)
 *   8. channels             (notification_destinations; archive, pause and resume FOR UPDATE, everyone else FOR NO KEY UPDATE)
 *   9. deliveries           (deliveries, FOR UPDATE)
 *
 * The Owner role row (MemberAccessGuard::lockOwners), a client and its sender quota (SendClientMessage) come before
 * level 1. Events are locked only by the planner, between its incident and its channels, and written by a digest's claim
 * and completion under the digest's channel; an event created in a transaction is a new row nobody else holds.
 *
 * Foreign keys are locks too. Inserting or re-pointing a referencing row takes FOR KEY SHARE on the referenced row; a
 * delete runs its ON DELETE action on every referencing row: CASCADE deletes it, SET NULL updates it, and RESTRICT or
 * NO ACTION checks it with FOR KEY SHARE (PostgreSQL ri_triggers.c), which waits for a referencing row held FOR UPDATE.
 * The key share counts at the referenced level, the action at the referencing level, so a path holds or locks that level
 * first. No runtime path deletes a client, channel, event or system issue (they are archived or resolved), so their
 * RESTRICT checks never run. The list below is derived from the schema (every pg_constraint foreign key mapped to these
 * levels):
 *
 *   users:        incidents.note_author_user_id (SaveIncidentNote), deliveries.actor_id (SendClientMessage),
 *                 notification_site_mutes.actor_id, check_snoozes.snoozed_by_/unsnoozed_by_user_id (the snooze actions):
 *                 each writer takes the user's key first. Deleting a user (DeleteProfile, the only path) SETs NULL on those
 *                 incidents (level 7), its channels (level 8) and those deliveries (level 9) and locks all three up front; the
 *                 SET NULL on check_run_attempts.actor_user_id, invitations.inviter_id and the other leaf tables and the
 *                 cascades (passkeys, client_user, notification_schedules, api_idempotency_records) are left to Postgres
 *   recurrences:  maintenance_periods.recurring_maintenance_period_id (KEY SHARE on insert: generation holds its recurrence
 *                 and inserts windows that take their site's key); deleting one SETs NULL on its windows (level 3). A site
 *                 deletion cascades to its recurrences, so DeleteSite takes them first
 *   windows:      incidents.opened_during_maintenance_window_id, deliveries.deferred_/blocking_maintenance_period_id
 *                 (KEY SHARE on insert or update); deleting one SETs NULL on those incidents (level 7) and deliveries
 *                 (level 9) and cascades to maintenance_site_exclusions and maintenance_catch_up_sites. FOR NO KEY UPDATE
 *                 does not conflict with KEY SHARE, so only catch-up and deletion (FOR UPDATE) block the recorder's
 *                 incident insert
 *   system issue: events.system_issue_id (KEY SHARE on insert; RESTRICT on delete)
 *   checks:       events.check_id (KEY SHARE on insert; SET NULL on delete), incidents.check_id (CASCADE)
 *   sites:        events.site_id (KEY SHARE on insert; SET NULL on delete), incidents.site_id and checks.site_id
 *                 (CASCADE: deleting a site deletes level 5 rows, so a site deletion locks its checks first)
 *   incidents:    events.incident_id (KEY SHARE on insert; SET NULL on delete), maintenance_catch_up_incidents (CASCADE)
 *   channels:     deliveries.destination_id (KEY SHARE on insert; RESTRICT on delete), notification_preferences and
 *                 notification_site_mutes (CASCADE)
 *   events:       deliveries.event_id (KEY SHARE on insert; CASCADE), deliveries.digest_event_id (RESTRICT)
 *
 * Claims never wait on a channel ({@see self::channels()} with $skipLocked): a busy channel leaves its delivery pending
 * for the next dispatch pass instead of holding the single delivery worker.
 *
 * Exclusive locks ($exclusive, FOR UPDATE) also block the FOR KEY SHARE of a referencing insert. Maintenance catch-up
 * (EmitMaintenanceCatchUps) and window deletion hold their window that way, so an incident the recorder opens in that
 * window waits for their commit; pause and resume (SetNotificationDestinationEnabled) and archive hold their channel that
 * way, so no delivery is inserted for it with the state they are changing. The recorder takes that window key below its
 * check; this cannot cycle with catch-up, which locks only the checks of incidents already open in the window, and a
 * check with an open incident never opens another.
 *
 * Routing reconciliation (RequestRoutingReconciliation) marks open uptime episodes: callers that already hold or will
 * lock a channel take those incidents first ({@see RequestRoutingReconciliation::lockEpisodes()}).
 *
 * Bulk delivery writes outside the channel paths lock their whole delivery set in ascending id before the first write
 * ({@see self::deliveries()}, {@see self::deliveriesMatching()}), as archive and the digest emitter do under their
 * channel. A path that resolves the incidents of several checks (pausing or deleting a site) locks the incidents and the
 * deliveries of all of them at once ({@see ResolveInactiveCheckIncidents::lockWork()}).
 *
 * Residual risk. Any cycle the schema inventory and the engine paths above do not close still ends as a Postgres 40P01
 * that aborts one side completely, never as a partial write or a silent loss. What each path does then is proven in
 * tests/Feature/NotificationChannelLockOrderTest.php:
 *   - a request (DeleteSite, DeleteProfile, the settings actions) runs its transaction once more
 *     ({@see self::transaction()}); a second deadlock rolls back completely and surfaces the error, and the user retries
 *     (tests/Feature/RequestDeadlockRetryTest.php; "rolls a site deletion aborted by a deadlock in its window cascade back
 *     completely, surfaces the error and completes on retry");
 *   - a dispatch job (catch-up, planning, digests) fails and the next pass redoes the work exactly once ("surfaces a
 *     catch-up aborted by a deadlock as a failed dispatch and emits it exactly once on the next pass");
 *   - a result recording runs once more at its outermost transaction, and a second deadlock records an errored run
 *     ("records an uptime failure exactly once when its recording deadlocks once ...", "records a serialized cron
 *     evaluation exactly once when its recording deadlocks once inside the executor's transaction", "fails a recording
 *     that deadlocks twice and records an errored run carrying the deadlock, as the failed job does").
 * Known instances: DeleteSite cascades into the site's own maintenance windows after its checks, site and deliveries,
 * while a catch-up or a window deletion holds its window and then takes check keys (windows and checks are not strictly
 * ordered); DeleteProfile's SET NULL on check_run_attempts.actor_user_id (attempt history, 7 days by default, without an index
 * on that column, and a rare deletion) can meet a recorder holding that attempt; the leaf tables of a user or site
 * deletion are written by the cascade, not locked up front.
 *
 * Locks the schema inventory cannot see, and why they do not cycle with the engine row locks:
 *   - advisory locks: SendClientMessage takes its replay key and its sender's quota key first in its transaction, before
 *     the client and every level, so they act as levels above the client and no other engine path takes them; the manual
 *     run budget (RunController) and heavy admission (ClaimService) keys come before the check and no engine path takes
 *     them; the session locks of check data maintenance and history pruning only serialize those scheduled commands;
 *   - explicit table locks: check data maintenance takes check_runs ACCESS EXCLUSIVE before it drops an expired
 *     partition and repairs check_run_attempts rows of those finished runs; it holds no engine row lock first and no
 *     recorder holds those attempts, so a recorder only waits on it;
 *   - triggers: the check_runs and check_run_attempts history triggers write leaf rows under the writer's check lock;
 *     check_runs_history_deleted locks the check FOR UPDATE on a run row delete, which happens only through DeleteSite's
 *     check cascade, which already holds those checks (a partition drop fires no row trigger).
 *
 * Known limit of the drift guard (tests/Arch/NotificationLockDrift.php): it sees explicit lock clauses only. A plain
 * UPDATE, DELETE or INSERT also locks rows (and an insert takes its foreign keys) without one, so bulk writes need review
 * against this hierarchy. Single-row writes outside a transaction (WakeUptimeRecoveries,
 * EmitNotificationDigests::suppressArchivedSources) hold one row lock at a time and cannot cycle.
 *
 * Window deletion (HandleDeferredUptimeOpening::deletingWindow) locks its whole referential-action set up front: the window
 * exclusive, every incident opened in it and every delivery that names it. A recurrence locks its windows before their
 * deletions; only future windows are deleted that way, and nothing refers to a window before it starts.
 *
 * A window wake (HandleDeferredUptimeOpening::wakeWindow) runs under its caller's window lock and updates the deliveries
 * that window blocks; a claim that re-points such a delivery to another active window of the site takes the keys of those
 * windows before the delivery ({@see self::maintenanceWindowKeys()}).
 *
 * The uptime recorder inserts events on the site's dependent checks (UptimeDependencies::release) under its site lock, so
 * it takes those checks' keys together with its own check, in ascending id, before the site
 * ({@see UptimeDependencies::dependentCheckIds()}).
 */
final class NotificationLocks
{
    /** One retry: a request whose first attempt Postgres aborts as a deadlock victim runs once more, a second deadlock surfaces. */
    public const int RequestAttempts = 2;

    /**
     * The transaction of a request-path engine action (a controller calls it). A deadlock (40P01) rolls the whole attempt
     * back, its after-commit callbacks included, and runs it once more, so a cycle nobody has closed yet heals instead of
     * failing the user's request. Laravel retries only an outermost transaction: nested in another one, the deadlock
     * propagates to that outer transaction, which is why a caller that wraps such an action in its own transaction (the
     * site settings form) uses this too. Jobs keep their own transactions: their retry is the job retry or the next pass.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function transaction(\Closure $callback): mixed
    {
        return DB::transaction($callback, self::RequestAttempts);
    }

    /**
     * The tables {@see self::forUpdate()} may lock: the levels above, the Owner role row, invitations (they come before
     * level 1 like the Owner role), clients, and the site exclusions of a window (taken under their window).
     */
    private const array ForUpdateTables = ['roles', 'invitations', 'users', 'clients', 'maintenance_periods', 'maintenance_site_exclusions',
        'system_issues', 'checks', 'sites', 'incidents', 'events', 'notification_destinations', 'deliveries'];

    /**
     * FOR UPDATE on the rows the caller's query selects, for paths that read a locked row with their own filter (the caller
     * keeps the hierarchy order). Fails on a table outside the hierarchy.
     */
    public static function forUpdate(QueryBuilder $query): QueryBuilder
    {
        return self::inHierarchy($query, self::ForUpdateTables)->lockForUpdate();
    }

    /** FOR SHARE on the client the caller's query selects: adding or resuming a site of a client that is not archived. */
    public static function forShare(QueryBuilder $query): QueryBuilder
    {
        return self::inHierarchy($query, ['clients'])->sharedLock();
    }

    /** FOR UPDATE SKIP LOCKED on the deliveries the caller's query selects: a claim skips a delivery someone else holds. */
    public static function forUpdateSkipLocked(QueryBuilder $query): QueryBuilder
    {
        return self::inHierarchy($query, ['deliveries'])->lock('FOR UPDATE SKIP LOCKED');
    }

    /**
     * With $exclusive, FOR UPDATE (profile deletion): every row that names the user as its actor waits for this
     * transaction, so a path that inserts such a row takes the user (without $exclusive) before its channel.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function users(iterable $ids, bool $exclusive = false): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('users')->where('id', $id)->lock($exclusive ? 'FOR UPDATE' : 'FOR KEY SHARE')->first(['id']);
        }
    }

    /**
     * With $exclusive, FOR UPDATE: what deleting the recurrence (it SETs NULL on its windows) and replacing its future
     * windows hold.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function maintenanceRecurrences(iterable $ids, bool $exclusive = false): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('recurring_maintenance_periods')->where('id', $id)->lock($exclusive ? 'FOR UPDATE' : 'FOR NO KEY UPDATE')->first(['id']);
        }
    }

    /**
     * With $exclusive, FOR UPDATE: inserts that refer to the window wait for this transaction (see the class comment).
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function maintenanceWindows(iterable $ids, bool $exclusive = false): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('maintenance_periods')->where('id', $id)->lock($exclusive ? 'FOR UPDATE' : 'FOR NO KEY UPDATE')->first(['id']);
        }
    }

    /**
     * Clients come before level 1 (FOR UPDATE: archiving or moving sites between them).
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function clients(iterable $ids): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('clients')->where('id', $id)->lockForUpdate()->first(['id']);
        }
    }

    /**
     * FOR KEY SHARE; the $exclusive checks (the ones a recorder or a monitoring change writes) join the set FOR UPDATE at
     * their place in the ascending order.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     * @param  iterable<mixed>  $exclusive  integer ids; null entries are skipped
     */
    public static function checks(iterable $ids, iterable $exclusive = []): void
    {
        $writes = self::ascending($exclusive);
        foreach (self::ascending([...$ids, ...$writes]) as $id) {
            DB::table('checks')->where('id', $id)->lock(in_array($id, $writes, true) ? 'FOR UPDATE' : 'FOR KEY SHARE')->first(['id']);
        }
    }

    /**
     * The recorder's check FOR UPDATE, returned as the full row, among the keys of $keys (FOR KEY SHARE) in ascending id.
     * Null when the check is gone.
     *
     * @param  iterable<mixed>  $keys  integer ids; null entries are skipped
     */
    public static function checkForUpdate(int $id, iterable $keys = []): ?Check
    {
        $others = array_values(array_diff(self::ascending($keys), [$id]));
        self::checks(array_filter($others, static fn (int $key): bool => $key < $id));
        $query = Check::query()->whereKey($id);
        $query->getQuery()->lockForUpdate();
        $check = $query->first();
        self::checks(array_filter($others, static fn (int $key): bool => $key > $id));

        return $check;
    }

    /**
     * FOR KEY SHARE: what pointing a delivery at a window takes. A claim re-points a deferred opening to the site's active
     * window while it holds the delivery, so it takes these keys first.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function maintenanceWindowKeys(iterable $ids): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('maintenance_periods')->where('id', $id)->lock('FOR KEY SHARE')->first(['id']);
        }
    }

    /**
     * With $exclusive, FOR UPDATE: pausing, deleting or moving a site, which also blocks event inserts that name it.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function sites(iterable $ids, bool $exclusive = false): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('sites')->where('id', $id)->lock($exclusive ? 'FOR UPDATE' : 'FOR NO KEY UPDATE')->first(['id']);
        }
    }

    /** @param iterable<mixed> $ids integer ids; null entries are skipped */
    public static function incidents(iterable $ids): void
    {
        foreach (self::ascending($ids) as $id) {
            DB::table('incidents')->where('id', $id)->lock('FOR NO KEY UPDATE')->first(['id']);
        }
    }

    /**
     * With $skipLocked the first busy channel stops the acquisition and false is returned; the caller must give up (its
     * transaction then releases what it already holds). With $exclusive, FOR UPDATE: delivery inserts for the channel
     * wait for this transaction (see the class comment).
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     */
    public static function channels(iterable $ids, bool $skipLocked = false, bool $exclusive = false): bool
    {
        $mode = $exclusive ? 'FOR UPDATE' : 'FOR NO KEY UPDATE';
        foreach (self::ascending($ids) as $id) {
            $row = DB::table('notification_destinations')->where('id', $id)
                ->lock($skipLocked ? $mode.' SKIP LOCKED' : $mode)->first(['id']);
            if ($row === null && $skipLocked) {
                return false;
            }
        }

        return true;
    }

    /**
     * FOR UPDATE, ascending. Returns the ids that exist.
     *
     * @param  iterable<mixed>  $ids  integer ids; null entries are skipped
     * @return list<int>
     */
    public static function deliveries(iterable $ids): array
    {
        $sorted = self::ascending($ids);
        if ($sorted === []) {
            return [];
        }

        /** @var list<int> */
        return DB::table('deliveries')->whereIn('id', $sorted)->orderBy('id')->lockForUpdate()->pluck('id')->all();
    }

    /**
     * FOR UPDATE, ascending, the deliveries whose ids $ids selects: for bulk writes over a site's whole history, without
     * loading the ids into PHP (one count row comes back). The rows are locked as the ordered scan returns them.
     */
    public static function deliveriesMatching(QueryBuilder $ids): void
    {
        $locking = DB::table('deliveries')->select('id')->whereIn('id', $ids)->orderBy('id')->lockForUpdate();
        DB::query()->fromSub($locking, 'locked')->count();
    }

    /** The channel of one delivery, read unlocked: a delivery never changes its channel. */
    public static function channelOf(int $deliveryId): ?int
    {
        $id = DB::table('deliveries')->where('id', $deliveryId)->value('destination_id');

        return is_int($id) ? $id : null;
    }

    /** @param list<string> $tables */
    private static function inHierarchy(QueryBuilder $query, array $tables): QueryBuilder
    {
        if (! is_string($query->from) || ! in_array($query->from, $tables, true)) {
            throw new \LogicException('This lock is not part of the notification lock hierarchy.');
        }

        return $query;
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<int>
     */
    private static function ascending(iterable $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }
            if (! is_int($id)) {
                throw new \LogicException('Lock ids must be integers.');
            }
            $unique[$id] = $id;
        }
        sort($unique);

        return $unique;
    }
}
