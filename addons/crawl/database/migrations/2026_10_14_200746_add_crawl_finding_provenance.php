<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['crawl_findings', 'mixed_content_findings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // No default or backfill: old rows have unknown provenance, and ALTER must not rewrite retained findings.
                $table->boolean('carried_forward')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['crawl_findings', 'mixed_content_findings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('carried_forward');
            });
        }
    }
};
