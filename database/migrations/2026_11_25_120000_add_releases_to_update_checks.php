<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            // The newest published releases as plain text parsed from their notes; null until a list was read.
            $table->jsonb('releases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->dropColumn('releases');
        });
    }
};
