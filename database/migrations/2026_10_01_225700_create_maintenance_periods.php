<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_maintenance_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('recurrence_type');
            $table->jsonb('days_of_week')->nullable();
            $table->unsignedSmallInteger('day_of_month')->nullable();
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('timezone');
            $table->date('last_generated_until')->nullable();
            $table->timestampsTz(6);
        });
        Schema::create('maintenance_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_maintenance_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6);
            $table->timestampsTz(6);
            $table->index(['site_id', 'starts_at', 'ends_at']);
            $table->unique(['recurring_maintenance_period_id', 'starts_at']);
        });
        DB::statement('ALTER TABLE maintenance_periods ADD CONSTRAINT maintenance_valid_interval CHECK (ends_at >= starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_periods');
        Schema::dropIfExists('recurring_maintenance_periods');
    }
};
