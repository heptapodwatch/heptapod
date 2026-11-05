<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->timestampTz('awaiting_result_since', 6)->nullable();
            $table->integer('lost_attempts')->default(0);
            $table->timestampTz('last_loss_started_at', 6)->nullable();
        });
        DB::statement('ALTER TABLE checks ADD CONSTRAINT checks_lost_attempts_nonnegative CHECK (lost_attempts >= 0)');
        DB::statement('CREATE INDEX checks_awaiting_result ON checks (awaiting_result_since) WHERE awaiting_result_since IS NOT NULL');
        // Fixed frontier and bounded batches; the per-check lookup uses the existing (check_id, id) index.
        // A terminal close since the last result is not detected: the episode may start earlier, never later.
        $frontier = DB::table('checks')->max('id') ?? 0;
        $last = 0;
        do {
            $ids = DB::table('checks')->where('id', '>', $last)->where('id', '<=', $frontier)
                ->whereNotNull('claim_token')->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            DB::update(<<<'SQL'
                UPDATE checks c SET awaiting_result_since = episode.since, lost_attempts = episode.lost, last_loss_started_at = episode.last_loss_started_at
                FROM (
                    SELECT checks.id, history.since, history.lost, history.last_loss_started_at
                    FROM checks CROSS JOIN LATERAL (
                        SELECT min(a.requested_at) AS since,
                            count(*) FILTER (WHERE a.note = 'lease_replaced')::integer AS lost,
                            (array_agg(a.started_at ORDER BY a.id DESC) FILTER (WHERE a.note = 'lease_replaced'))[1] AS last_loss_started_at
                        FROM check_run_attempts a
                        WHERE a.check_id = checks.id
                            AND a.requested_at > COALESCE(checks.latest_run_ended_at, '-infinity'::timestamptz)
                            AND (a.phase IN ('queued', 'running') OR (a.phase = 'skipped' AND a.note = 'lease_replaced'))
                    ) history
                    WHERE checks.id > ? AND checks.id <= ? AND checks.claim_token IS NOT NULL
                ) episode WHERE c.id = episode.id
                SQL, [$last, $ids->last()]);
            $last = $ids->last();
        } while ($ids->count() === 500);
    }

    public function down(): never
    {
        throw new LogicException('Forward-only: freshness episodes cannot be reconstructed after rollback. Restore the pre-upgrade backup instead.');
    }
};
