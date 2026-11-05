<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('last_outcome')->nullable();
            $table->string('last_outcome_diagnosis')->nullable();
            $table->timestampTz('last_outcome_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['last_outcome', 'last_outcome_diagnosis', 'last_outcome_at']);
        });
    }
};
