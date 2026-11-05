<?php

declare(strict_types=1);

use Heptapod\Enums\AlertSensitivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            if (! Schema::hasColumn('incidents', 'alert_episode_started_at')) {
                $table->timestampTz('alert_episode_started_at', 6)->nullable();
                $table->smallInteger('alert_after_minutes')->nullable();
                $table->smallInteger('alert_recovery_checks')->nullable();
            }
        });
        if (! DB::table('pg_constraint')->where('conrelid', DB::raw("'incidents'::regclass"))->where('conname', 'incidents_alert_episode_snapshot')->exists()) {
            $min = AlertSensitivity::MinStoredMinutes;
            $max = AlertSensitivity::MaxStoredMinutes;
            $recoveryMin = AlertSensitivity::MinRecoveryChecks;
            $recoveryMax = AlertSensitivity::MaxRecoveryChecks;
            DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_alert_episode_snapshot CHECK ((alert_episode_started_at IS NULL AND alert_after_minutes IS NULL AND alert_recovery_checks IS NULL) OR (alert_episode_started_at IS NOT NULL AND alert_after_minutes IS NOT NULL AND alert_recovery_checks IS NOT NULL AND alert_after_minutes BETWEEN {$min} AND {$max} AND alert_recovery_checks BETWEEN {$recoveryMin} AND {$recoveryMax}))");
        }
        Schema::table('deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('deliveries', 'deferred_maintenance_period_id')) {
                $table->foreignId('deferred_maintenance_period_id')->nullable()->constrained('maintenance_periods')->nullOnDelete();
            }
            if (! Schema::hasColumn('deliveries', 'blocking_maintenance_period_id')) {
                $table->foreignId('blocking_maintenance_period_id')->nullable()->constrained('maintenance_periods')->nullOnDelete();
            }
        });
        DB::statement('CREATE INDEX IF NOT EXISTS deliveries_blocking_maintenance ON deliveries (blocking_maintenance_period_id, id) WHERE blocking_maintenance_period_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS deliveries_deferred_maintenance ON deliveries (deferred_maintenance_period_id, id) WHERE deferred_maintenance_period_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS incidents_alert_episode ON incidents (check_id, alert_episode_started_at, opened_at, id) WHERE alert_episode_started_at IS NOT NULL');
        DB::statement("CREATE INDEX IF NOT EXISTS events_check_recovery ON events (check_id, incident_id, id) WHERE type = 'incident.resolved'");
        DB::statement("CREATE INDEX IF NOT EXISTS events_admin_recovery_incident ON events (incident_id) WHERE type = 'incident.resolved' AND suppression_reason = 'monitoring_stopped'");
        DB::statement("CREATE INDEX IF NOT EXISTS deliveries_recovery_waiting_event ON deliveries (event_id, id) WHERE last_reason IN ('recovery_checks_pending', 'recovery_checks_ready') AND state = 'pending' AND NOT suppressed");
    }

    public function down(): void {}
};
