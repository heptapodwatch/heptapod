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
        if (! Schema::hasColumn('sites', 'alert_after_minutes')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->smallInteger('alert_after_minutes')->nullable();
                $table->smallInteger('alert_recovery_checks')->nullable();
            });
        }
        if (! DB::table('pg_constraint')->where('conrelid', DB::raw("'sites'::regclass"))->where('conname', 'sites_alert_sensitivity_pair')->exists()) {
            $min = AlertSensitivity::MinStoredMinutes;
            $max = AlertSensitivity::MaxStoredMinutes;
            $recoveryMin = AlertSensitivity::MinRecoveryChecks;
            $recoveryMax = AlertSensitivity::MaxRecoveryChecks;
            DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_alert_sensitivity_pair CHECK ((alert_after_minutes IS NULL AND alert_recovery_checks IS NULL) OR (alert_after_minutes IS NOT NULL AND alert_recovery_checks IS NOT NULL AND alert_after_minutes BETWEEN {$min} AND {$max} AND alert_recovery_checks BETWEEN {$recoveryMin} AND {$recoveryMax}))");
        }
    }

    public function down(): void {}
};
