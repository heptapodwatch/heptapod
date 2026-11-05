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
        Schema::create('cron_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type');
            $table->integer('frequency_in_minutes')->nullable();
            $table->integer('grace_time_in_minutes')->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('server_timezone')->nullable();
            $table->text('description')->nullable();
            $table->string('latest_result')->default('pending');
            $table->timestampTz('latest_ping_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampTz('next_due_at', 6);
            $table->bigInteger('ping_revision')->default(0);
            $table->integer('late_by_seconds')->default(0);
            $table->jsonb('ping_meta')->nullable();
            $table->string('evaluated_result')->nullable();
            $table->bigInteger('evaluated_revision')->nullable();
            $table->bigInteger('failure_revision')->default(0);
            $table->bigInteger('evaluated_failure_revision')->default(0);
            $table->timestampsTz(6);
            $table->index('check_id');
        });
        DB::statement("ALTER TABLE cron_checks ADD CONSTRAINT cron_checks_definition CHECK (
            (grace_time_in_minutes IS NULL OR grace_time_in_minutes >= 0) AND (
                (type = 'simple' AND frequency_in_minutes IS NOT NULL AND frequency_in_minutes > 0 AND cron_expression IS NULL AND server_timezone IS NULL)
                OR (type = 'cron' AND frequency_in_minutes IS NULL AND cron_expression IS NOT NULL AND server_timezone IS NOT NULL)
            )
        )");
        DB::statement("ALTER TABLE cron_checks ADD CONSTRAINT cron_checks_results CHECK (
            latest_result IN ('pending', 'succeeded', 'warning', 'failed')
            AND (evaluated_result IS NULL OR evaluated_result IN ('succeeded', 'warning', 'failed'))
        )");

        Schema::create('cron_pings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cron_check_id')->constrained('cron_checks')->cascadeOnDelete();
            $table->string('phase');
            $table->bigInteger('ping_revision');
            $table->string('result');
            $table->jsonb('meta');
            $table->timestampTz('received_at', 6)->index();
            $table->unique(['cron_check_id', 'ping_revision']);
        });

        Schema::create('cron_run_tasks', function (Blueprint $table): void {
            $table->bigInteger('run_id');
            $table->uuid('claim_token');
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('cron_check_id')->constrained('cron_checks')->cascadeOnDelete();
            $table->string('result');
            $table->bigInteger('ping_revision');
            $table->bigInteger('failure_revision');
            $table->text('failure_message')->nullable();
            $table->integer('late_by_seconds');
            // No FK to partitioned check_runs: addon retention must not block raw partition pruning.
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->primary(['run_id', 'claim_token', 'cron_check_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_run_tasks');
        Schema::dropIfExists('cron_pings');
        Schema::dropIfExists('cron_checks');
    }
};
