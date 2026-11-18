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
            $table->unsignedBigInteger('oh_dear_id')->nullable()->unique();
        });
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->jsonb('channel_plan')->default('[]');
            $table->jsonb('channel_results')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn(['channel_plan', 'channel_results']);
        });
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn('oh_dear_id');
        });
    }
};
