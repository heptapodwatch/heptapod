<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markers record that a window's start or end boundary has been announced to live clients.
     * Boundaries already in the past when this migration runs are marked, so the first scheduler
     * tick does not replay the history of every window.
     */
    public function up(): void
    {
        Schema::table('maintenance_periods', function (Blueprint $table): void {
            $table->timestampTz('start_signalled_at', 6)->nullable();
            $table->timestampTz('end_signalled_at', 6)->nullable();
        });

        $capturedAt = now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('maintenance_periods')->where('starts_at', '<=', $capturedAt)
            ->whereNull('start_signalled_at')->update(['start_signalled_at' => $capturedAt]);
        DB::table('maintenance_periods')->where('ends_at', '<=', $capturedAt)
            ->whereNull('end_signalled_at')->update(['end_signalled_at' => $capturedAt]);

        DB::statement('CREATE INDEX maintenance_periods_start_unsignalled ON maintenance_periods (starts_at) WHERE start_signalled_at IS NULL');
        DB::statement('CREATE INDEX maintenance_periods_end_unsignalled ON maintenance_periods (ends_at) WHERE end_signalled_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS maintenance_periods_start_unsignalled');
        DB::statement('DROP INDEX IF EXISTS maintenance_periods_end_unsignalled');
        Schema::table('maintenance_periods', function (Blueprint $table): void {
            $table->dropColumn(['start_signalled_at', 'end_signalled_at']);
        });
    }
};
