<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_checks', function (Blueprint $table): void {
            $table->timestampTz('evaluated_failure_at', 6)->nullable();
        });
        Schema::table('cron_run_tasks', function (Blueprint $table): void {
            $table->timestampTz('failure_started_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cron_run_tasks', function (Blueprint $table): void {
            $table->dropColumn('failure_started_at');
        });
        Schema::table('cron_checks', function (Blueprint $table): void {
            $table->dropColumn('evaluated_failure_at');
        });
    }
};
