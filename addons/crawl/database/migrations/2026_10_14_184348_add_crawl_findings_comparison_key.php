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
            // The collection fingerprint excludes evaluator settings such as the whitelist. Historic values cannot be reconstructed.
            $table->string('comparison_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->dropColumn('comparison_key');
        });
    }
};
