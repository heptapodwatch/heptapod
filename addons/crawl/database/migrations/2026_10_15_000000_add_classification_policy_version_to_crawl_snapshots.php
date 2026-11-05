<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Existing snapshots keep NULL: the policy that labeled them is unknown. */
    public function up(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->unsignedSmallInteger('classification_policy_version')->nullable();
        });
        Schema::table('crawl_findings', function (Blueprint $table): void {
            $table->text('current_error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_snapshots', function (Blueprint $table): void {
            $table->dropColumn('classification_policy_version');
        });
        Schema::table('crawl_findings', function (Blueprint $table): void {
            $table->dropColumn('current_error_message');
        });
    }
};
