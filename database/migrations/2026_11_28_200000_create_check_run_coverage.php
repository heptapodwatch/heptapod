<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What check data maintenance has proven about the rollups (CheckRunCoverage). It lives in the database beside the runs
 * and rollups it describes, so a restore brings both back together and raw edits can invalidate it in their transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_run_rollup_coverage', function (Blueprint $table): void {
            $table->boolean('singleton')->primary()->default(true);
            $table->timestampTz('covered_until', 6);
        });
        DB::statement('ALTER TABLE check_run_rollup_coverage ADD CONSTRAINT check_run_rollup_coverage_singleton CHECK (singleton)');
        Schema::create('check_run_partition_finalisations', function (Blueprint $table): void {
            $table->timestampTz('partition_start', 6)->primary();
            $table->timestampTz('partition_end', 6);
            $table->timestampTz('finalised_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_run_partition_finalisations');
        Schema::dropIfExists('check_run_rollup_coverage');
    }
};
