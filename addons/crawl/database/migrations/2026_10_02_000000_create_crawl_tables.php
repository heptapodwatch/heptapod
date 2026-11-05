<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_collections', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->primary();
            $table->jsonb('artifact');
            $table->timestampTz('collected_at', 6);
        });
        Schema::create('crawl_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('check_id')->index();
            $table->unsignedBigInteger('run_id');
            $table->uuid('claim_token');
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->timestampTz('collected_at', 6);
            $table->jsonb('settings');
            $table->boolean('truncated');
            $table->jsonb('observed_urls');
            $table->jsonb('observed_pages');
            $table->jsonb('discovered_urls');
            $table->boolean('discovery_complete');
            $table->jsonb('evaluated_finding_ids');
            $table->string('site_url', 4096);
            $table->string('target_fingerprint', 64);
            $table->unique(['run_id', 'claim_token']);
        });
        Schema::create('crawl_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('crawl_snapshots')->cascadeOnDelete();
            $table->text('crawled_url');
            $table->text('relative_crawled_url');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('found_on_url')->nullable();
            $table->text('relative_found_on_url')->nullable();
            $table->text('link_text')->nullable();
            $table->string('type');
            $table->text('error_message')->nullable();
            $table->boolean('internal');
        });
        Schema::create('mixed_content_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('crawl_snapshots')->cascadeOnDelete();
            $table->string('element_name');
            $table->text('mixed_content_url');
            $table->text('source_url');
            $table->text('found_on_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mixed_content_findings');
        Schema::dropIfExists('crawl_findings');
        Schema::dropIfExists('crawl_snapshots');
        Schema::dropIfExists('crawl_collections');
    }
};
