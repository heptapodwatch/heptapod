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
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('alert_confirms_other_checks')->nullable();
            $table->string('alert_reminders', 16)->nullable();
        });
        // Same mapping as the instance settings migration of this date: a former preset (2/1, 5/3, 10/3, 30/3) moves to its
        // current settings; any other (Custom) override keeps its numbers, no confirmation and standard reminders.
        DB::statement(<<<'SQL'
            UPDATE sites SET
                alert_after_minutes = CASE WHEN alert_recovery_checks = 3 AND alert_after_minutes = 10 THEN 30
                    WHEN alert_recovery_checks = 3 AND alert_after_minutes = 30 THEN 60 ELSE alert_after_minutes END,
                alert_confirms_other_checks = alert_recovery_checks = 3 AND alert_after_minutes IN (5, 10, 30),
                alert_reminders = CASE WHEN alert_recovery_checks = 3 AND alert_after_minutes IN (10, 30) THEN 'daily' ELSE 'standard' END
            WHERE alert_after_minutes IS NOT NULL
            SQL);
        $min = AlertSensitivity::MinStoredMinutes;
        $max = AlertSensitivity::MaxStoredMinutes;
        $recoveryMin = AlertSensitivity::MinRecoveryChecks;
        $recoveryMax = AlertSensitivity::MaxRecoveryChecks;
        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_alert_sensitivity_pair');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_alert_sensitivity_override CHECK ((alert_after_minutes IS NULL AND alert_recovery_checks IS NULL AND alert_confirms_other_checks IS NULL AND alert_reminders IS NULL) OR (alert_after_minutes IS NOT NULL AND alert_recovery_checks IS NOT NULL AND alert_confirms_other_checks IS NOT NULL AND alert_reminders IN ('standard', 'daily') AND alert_after_minutes BETWEEN {$min} AND {$max} AND alert_recovery_checks BETWEEN {$recoveryMin} AND {$recoveryMax}))");
    }

    public function down(): void {}
};
