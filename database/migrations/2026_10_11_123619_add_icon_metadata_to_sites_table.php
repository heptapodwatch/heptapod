<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('icon_hash', 64)->nullable();
            $table->timestampTz('icon_fetched_at')->nullable();
            $table->timestampTz('icon_attempted_at')->nullable()->index();
            $table->timestampTz('icon_manual_requested_at')->nullable();
            $table->jsonb('icon_manual_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['icon_attempted_at']);
            $table->dropColumn(['icon_hash', 'icon_fetched_at', 'icon_attempted_at', 'icon_manual_requested_at', 'icon_manual_result']);
        });
    }
};
