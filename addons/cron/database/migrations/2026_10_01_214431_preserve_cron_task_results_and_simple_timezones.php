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
        Schema::table('cron_checks', function (Blueprint $table): void {
            $table->string('aggregate_result')->default('pending');
        });
        DB::statement('ALTER TABLE cron_checks DROP CONSTRAINT cron_checks_results, DROP CONSTRAINT cron_checks_definition');
        DB::statement("UPDATE cron_checks SET aggregate_result = latest_result, latest_result = CASE
            WHEN latest_result IN ('succeeded', 'warning') THEN 'pingFinished'
            WHEN latest_result = 'failed' THEN CASE WHEN
                (SELECT result FROM cron_pings WHERE cron_check_id = cron_checks.id AND phase <> 'starting' ORDER BY ping_revision DESC LIMIT 1) = 'failed'
                THEN 'pingFailed' ELSE 'pingMissed' END
            ELSE 'pending' END");
        DB::statement("ALTER TABLE cron_checks ADD CONSTRAINT cron_checks_results CHECK (
            latest_result IN ('pending', 'pingFinished', 'pingFailed', 'pingMissed')
            AND aggregate_result IN ('pending', 'succeeded', 'warning', 'failed')
            AND (evaluated_result IS NULL OR evaluated_result IN ('succeeded', 'warning', 'failed'))
        )");
        $this->definitionConstraint(false);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cron_checks DROP CONSTRAINT cron_checks_results, DROP CONSTRAINT cron_checks_definition');
        DB::statement('UPDATE cron_checks SET latest_result = aggregate_result');
        DB::statement("UPDATE cron_checks SET server_timezone = NULL WHERE type = 'simple'");
        Schema::table('cron_checks', function (Blueprint $table): void {
            $table->dropColumn('aggregate_result');
        });
        DB::statement("ALTER TABLE cron_checks ADD CONSTRAINT cron_checks_results CHECK (
            latest_result IN ('pending', 'succeeded', 'warning', 'failed')
            AND (evaluated_result IS NULL OR evaluated_result IN ('succeeded', 'warning', 'failed'))
        )");
        $this->definitionConstraint(true);
    }

    private function definitionConstraint(bool $requireNullTimezone): void
    {
        $timezone = $requireNullTimezone ? 'AND server_timezone IS NULL' : '';
        DB::statement("ALTER TABLE cron_checks ADD CONSTRAINT cron_checks_definition CHECK (
            (grace_time_in_minutes IS NULL OR grace_time_in_minutes >= 0) AND (
                (type = 'simple' AND frequency_in_minutes IS NOT NULL AND frequency_in_minutes > 0 AND cron_expression IS NULL {$timezone})
                OR (type = 'cron' AND frequency_in_minutes IS NULL AND cron_expression IS NOT NULL AND server_timezone IS NOT NULL)
            )
        )");
    }
};
