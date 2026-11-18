<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            // Older runs always covered these three sections.
            $table->jsonb('sections')->default('["sites","maintenance","cron"]');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn('sections');
        });
    }
};
