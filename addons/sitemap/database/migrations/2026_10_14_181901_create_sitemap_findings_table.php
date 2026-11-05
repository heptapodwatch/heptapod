<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sitemap_snapshots', function (Blueprint $table): void {
            $table->jsonb('findings_coverage')->nullable();
            $table->string('comparison_key', 64)->nullable();
        });
        Schema::create('sitemap_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('sitemap_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->jsonb('identity');
            $table->string('identity_hash', 64);
            $table->string('status');
            $table->string('issue_code')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('kind')->nullable();
            $table->text('url');
            $table->string('reason')->nullable();
            $table->boolean('carried_forward')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitemap_findings');
        Schema::table('sitemap_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['findings_coverage', 'comparison_key']);
        });
    }
};
