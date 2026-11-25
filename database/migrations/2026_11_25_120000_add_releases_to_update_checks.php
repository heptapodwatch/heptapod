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
            // The newest page of releases as plain text parsed from their notes; null when the list could not be read.
            $table->jsonb('releases')->nullable();
            // Release links are built from the configured release page and the tag, never stored from the source.
            $table->dropColumn('release_url');
        });
    }

    public function down(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->dropColumn('releases');
            $table->string('release_url')->nullable();
        });
    }
};
