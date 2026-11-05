<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->uuid('claim_token');
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->jsonb('snapshot');
            $table->jsonb('expected');
            $table->timestampTz('created_at', 6);
            $table->unique(['run_id', 'claim_token']);
        });
        Schema::create('dns_baselines', function (Blueprint $table): void {
            $table->foreignId('check_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->jsonb('snapshot');
            $table->jsonb('expected');
        });
        Schema::create('dns_history_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->timestampTz('run_ended_at', 6);
            $table->boolean('initial');
            $table->jsonb('authoritative_nameservers');
            $table->jsonb('raw_dns_records');
            $table->jsonb('dns_records');
            $table->jsonb('normalized_records');
            $table->jsonb('issues');
            $table->jsonb('diff');
            $table->string('diff_summary');
            $table->timestampTz('created_at', 6)->index();
            $table->unique(['check_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_history_items');
        Schema::dropIfExists('dns_baselines');
        Schema::dropIfExists('dns_observations');
    }
};
