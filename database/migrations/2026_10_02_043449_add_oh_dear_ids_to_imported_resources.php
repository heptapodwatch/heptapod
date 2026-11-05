<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'maintenance_periods', 'recurring_maintenance_periods'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->bigInteger('oh_dear_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['sites', 'maintenance_periods', 'recurring_maintenance_periods'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('oh_dear_id');
            });
        }
    }
};
