<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_rules', function (Blueprint $table): void {
            $table->jsonb('weekdays')->nullable();
            $table->string('window_start', 5)->nullable();
            $table->string('window_end', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_rules', function (Blueprint $table): void {
            $table->dropColumn(['weekdays', 'window_start', 'window_end']);
        });
    }
};
