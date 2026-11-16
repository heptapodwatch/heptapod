<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The scheduled activitylog:clean and queue:prune-failed delete by these timestamps alone.
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index('created_at');
        });
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropIndex(['failed_at']);
        });
    }
};
