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
        Schema::create('unmonitored_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->index(['site_id', 'started_at', 'ended_at']);
            $table->index(['check_id', 'started_at', 'ended_at']);
        });
        DB::statement('INSERT INTO unmonitored_periods (site_id, started_at) SELECT id, paused_at FROM sites WHERE paused_at IS NOT NULL');
        // Older disable/resume intervals were not recorded. Do not infer them from run history.
        DB::statement("INSERT INTO unmonitored_periods (site_id, check_id, started_at) SELECT site_id, id, updated_at FROM checks WHERE type = 'uptime' AND NOT enabled");
    }

    public function down(): void
    {
        Schema::dropIfExists('unmonitored_periods');
    }
};
