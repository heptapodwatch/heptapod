<?php

declare(strict_types=1);

use Heptapod\Enums\NotificationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System issue mail joins the one delivery ledger (design 2026-10-29 rev. 3 §1.1, §3.4, §5 step 8). Every
 * system_issue_deliveries row becomes a delivery on its user's account channel under one event per issue and phase;
 * users.system_issue_mails already became the Admin's System cell in 2026_10_30_000001.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE users, notification_destinations, system_issues, system_issue_deliveries, events, deliveries IN SHARE ROW EXCLUSIVE MODE');
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('system_issue_id')->nullable()->constrained('system_issues')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX events_system_issue_phase ON events (system_issue_id, type) WHERE system_issue_id IS NOT NULL');
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (type = 'notification.digest'"
            ." OR (type IN ('system.issue_opened', 'system.issue_resolved') AND system_issue_id IS NOT NULL AND site_id IS NULL)"
            .' OR (site_name IS NOT NULL AND site_url IS NOT NULL AND client_name IS NOT NULL AND client_id IS NOT NULL))');
        // A rejected transport probe stays as history; a re-armed probe to the same channel is its next round.
        // Every other delivery is round 0, so (event, channel) stays unique for them.
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('probe_round')->default(0);
            $table->dropUnique(['event_id', 'destination_id']);
            $table->unique(['event_id', 'destination_id', 'probe_round']);
        });
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_probe_round CHECK (probe_round = 0 OR (category = 'system') IS TRUE)");
        // The evaluator's System drain (SystemIssueMailer::due) reads only unfinished System rows, earliest due first.
        DB::statement("CREATE INDEX deliveries_system_unfinished ON deliveries (next_attempt_at, id) WHERE category = 'system' AND NOT suppressed AND state IN ('pending', 'failed', 'sending')");
        $lines = $this->moveDeliveries();
        Schema::drop('system_issue_deliveries');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('system_issue_mails');
        });
        if ($lines !== []) {
            $this->report($lines);
        }
    }

    public function down(): never
    {
        throw new LogicException('System issue deliveries now live in the delivery ledger. Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
    }

    /** @return list<string> */
    private function moveDeliveries(): array
    {
        $now = now('UTC')->format('Y-m-d H:i:s.uP');
        $lines = [];
        $counts = ['sent' => 0, 'suppressed' => 0, 'failed' => 0, 'pending' => 0, 'probe' => 0];
        $events = [];
        $channels = [];
        $rows = DB::table('system_issue_deliveries')->join('system_issues', 'system_issues.id', '=', 'system_issue_deliveries.system_issue_id')
            ->orderBy('system_issue_deliveries.id')
            ->get(['system_issue_deliveries.*', 'system_issues.opened_at', 'system_issues.resolved_at']);
        foreach ($rows as $row) {
            $issueId = $this->int($row->system_issue_id);
            $userId = $this->int($row->user_id);
            $phase = $row->phase === 'open' ? 'open' : ($row->phase === 'recovery' ? 'recovery' : throw new LogicException('Unknown system issue delivery phase.'));
            $events[$issueId.':'.$phase] ??= $this->event($issueId, $phase, $now);
            $channels[$userId] ??= $this->accountChannel($userId, $now, $lines);
            $delivered = $this->time($row->delivered_at);
            $suppressedAt = $this->time($row->suppressed_at);
            $attemptedAt = $this->time($row->last_attempt_at);
            $error = $row->last_error === null ? null : (is_string($row->last_error) ? $row->last_error : throw new LogicException('System issue delivery error must be text.'));
            $probe = $phase === 'recovery' && $row->resolved_at === null && $delivered === null && $suppressedAt === null;
            $state = match (true) {
                $delivered !== null => 'sent',
                $suppressedAt !== null => 'suppressed',
                $probe => 'probe',
                $error !== null => 'failed',
                default => 'pending',
            };
            $counts[$state]++;
            DB::table('deliveries')->insert([
                'event_id' => $events[$issueId.':'.$phase], 'destination_id' => $channels[$userId],
                'state' => match ($state) {
                    'sent' => 'sent', 'failed' => 'failed', default => 'pending',
                },
                'attempts' => $this->int($row->attempts),
                // A due retry keeps the time of its last attempt; never attempted work is due now.
                'next_attempt_at' => $attemptedAt ?? $now,
                'sent_at' => $delivered,
                'last_reason' => match ($state) {
                    'probe' => 'system_probe', 'failed' => 'notifications.transport_failed', default => null,
                },
                'last_diagnosis' => $error,
                'suppressed' => $state === 'suppressed',
                'suppression_reason' => $state === 'suppressed' ? $row->suppression_reason : null,
                'suppression_evaluated_at' => $suppressedAt ?? $delivered ?? $attemptedAt ?? $now,
                'origin' => 'system', 'category' => NotificationCategory::System->value,
            ]);
        }

        if (count($rows) === 0) {
            return $lines;
        }

        return [...$lines, 'System issue mail: '.count($rows).' deliveries moved into the delivery ledger ('
            .implode(', ', array_map(static fn (string $state, int $count): string => $count.' '.$state, array_keys($counts), $counts)).').'];
    }

    /** One event per issue and phase. A recovery of a still-open issue is the transport probe's recovery mail. */
    private function event(int $issueId, string $phase, string $now): int
    {
        $issue = DB::table('system_issues')->where('id', $issueId)->sole(['opened_at', 'resolved_at']);
        $firstAttempt = DB::table('system_issue_deliveries')->where('system_issue_id', $issueId)->where('phase', 'recovery')->min('last_attempt_at');
        $occurredAt = $phase === 'open' ? $issue->opened_at : ($issue->resolved_at ?? $firstAttempt ?? $issue->opened_at);

        return DB::table('events')->insertGetId([
            'type' => $phase === 'open' ? 'system.issue_opened' : 'system.issue_resolved', 'system_issue_id' => $issueId,
            'occurred_at' => $occurredAt, 'payload' => json_encode($phase === 'open' ? (object) [] : ['probe' => $issue->resolved_at === null], JSON_THROW_ON_ERROR),
            'suppressed' => false, 'suppression_evaluated_at' => $now, 'notification_routed_at' => $now,
        ]);
    }

    /** @param list<string> $lines */
    private function accountChannel(int $userId, string $now, array &$lines): int
    {
        $id = DB::table('notification_destinations')->where('user_id', $userId)->where('account_mail', true)->value('id');
        if ($id !== null) {
            return $this->int($id);
        }
        // History must stay attached to its recipient; a missing account channel is recreated archived, never sending.
        $user = DB::table('users')->where('id', $userId)->sole(['name']);
        $id = DB::table('notification_destinations')->insertGetId(['label' => $user->name, 'channel' => 'mail', 'locale' => null, 'enabled' => true,
            'config' => '{}', 'recipient_kind' => 'user', 'user_id' => $userId, 'account_mail' => true,
            'removed_at' => $now, 'removed_reason' => 'archived', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('notification_preferences')->insert(array_map(static fn (NotificationCategory $category): array => [
            'destination_id' => $id, 'category' => $category->value, 'mode' => 'off'], NotificationCategory::grid()));
        $lines[] = "User #{$userId}: account email channel #{$id} added archived to keep its system issue mail history.";

        return $id;
    }

    /** @param list<string> $lines */
    private function report(array $lines): void
    {
        // The conversion report is one audit entry (design §5); system issue lines join the one 000001 wrote.
        $report = DB::table('activity_log')->where('event', 'notifications.migrated')->orderByDesc('id')->first(['id', 'properties']);
        if ($report === null) {
            activity('audit')->event('notifications.migrated')->withProperties(['lines' => $lines])->log('notifications.migrated');

            return;
        }
        $properties = json_decode(is_string($report->properties) ? $report->properties : '{}', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($properties) || ! is_array($properties['lines'] ?? null)) {
            throw new LogicException('The notification conversion report has no lines.');
        }
        DB::table('activity_log')->where('id', $report->id)->update(['properties' => json_encode([...$properties, 'lines' => [...$properties['lines'], ...$lines]], JSON_THROW_ON_ERROR)]);
    }

    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : throw new LogicException('System issue delivery ids must be integers.'));
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : (is_string($value) ? $value : throw new LogicException('System issue delivery times must be text.'));
    }
};
