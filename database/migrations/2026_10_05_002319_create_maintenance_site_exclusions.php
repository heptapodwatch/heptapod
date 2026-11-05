<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_site_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_period_id')->constrained('maintenance_periods')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestampTz('left_at', 6);
            $table->timestampTz('catch_up_processed_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['maintenance_period_id', 'site_id']);
            $table->index(['left_at', 'catch_up_processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_site_exclusions');
    }
};
