<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_baselines', function (Blueprint $table): void {
            $table->unsignedBigInteger('warning_episode_run_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dns_baselines', function (Blueprint $table): void {
            $table->dropColumn('warning_episode_run_id');
        });
    }
};
