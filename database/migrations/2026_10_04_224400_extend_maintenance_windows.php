<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['maintenance_periods', 'recurring_maintenance_periods'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('target_type', 6)->default('site');
                $blueprint->unsignedBigInteger('target_id')->nullable();
                $blueprint->string('reason')->nullable();
                $blueprint->boolean('mute_alerts')->default(true);
                $blueprint->boolean('catch_up_at_end')->default(true);
                $blueprint->index(['target_type', 'target_id']);
            });
            DB::table($table)->update(['target_id' => DB::raw('site_id')]);
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('site_id')->nullable()->change());
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_target_valid CHECK ((target_type = 'site' AND coalesce(target_id, site_id) IS NOT NULL) OR (target_type IN ('client', 'tag') AND target_id IS NOT NULL))");
        }
        Schema::table('recurring_maintenance_periods', function (Blueprint $table): void {
            $table->unsignedSmallInteger('interval')->default(1);
            $table->unsignedSmallInteger('nth_weekday')->nullable();
            $table->unsignedSmallInteger('day_of_week')->nullable();
            $table->date('anchor_date')->nullable();
        });
        Schema::table('incidents', fn (Blueprint $table) => $table->foreignId('opened_during_maintenance_window_id')->nullable()->constrained('maintenance_periods')->nullOnDelete());
        Schema::create('maintenance_catch_up_incidents', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->primary(['event_id', 'incident_id']);
            $table->index(['incident_id', 'event_id']);
        });
        Schema::create('maintenance_catch_up_sites', function (Blueprint $table): void {
            $table->foreignId('maintenance_period_id')->constrained('maintenance_periods')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->primary(['maintenance_period_id', 'site_id']);
        });
        Schema::table('maintenance_periods', function (Blueprint $table): void {
            $table->timestampTz('catch_up_processed_at', 6)->nullable();
            $table->string('timezone')->nullable();
            $table->index(['ends_at', 'catch_up_processed_at']);
        });
    }

    public function down(): void
    {
        foreach (['maintenance_periods', 'recurring_maintenance_periods'] as $table) {
            if (DB::table($table)->whereNull('site_id')->exists()) {
                throw new LogicException('Remove non-site maintenance targets before rolling back this migration.');
            }
        }
        Schema::dropIfExists('maintenance_catch_up_sites');
        Schema::dropIfExists('maintenance_catch_up_incidents');
        Schema::table('incidents', fn (Blueprint $table) => $table->dropConstrainedForeignId('opened_during_maintenance_window_id'));
        Schema::table('maintenance_periods', fn (Blueprint $table) => $table->dropColumn(['catch_up_processed_at', 'timezone']));
        Schema::table('recurring_maintenance_periods', fn (Blueprint $table) => $table->dropColumn(['interval', 'nth_weekday', 'day_of_week', 'anchor_date']));
        foreach (['maintenance_periods', 'recurring_maintenance_periods'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_target_valid");
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['target_type', 'target_id', 'reason', 'mute_alerts', 'catch_up_at_end']);
                $blueprint->foreignId('site_id')->nullable(false)->change();
            });
        }
    }
};
