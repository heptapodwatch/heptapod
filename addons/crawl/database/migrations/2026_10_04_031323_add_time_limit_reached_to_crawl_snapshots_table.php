<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->boolean('time_limit_reached')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->dropColumn('time_limit_reached');
        });
    }
};
