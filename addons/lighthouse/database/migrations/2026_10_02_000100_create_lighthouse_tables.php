<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lighthouse_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->uuid('claim_token');
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->text('compressed_report');
            $table->jsonb('metrics');
            $table->jsonb('evidence');
            $table->timestampTz('created_at', 6);
            $table->unique(['run_id', 'claim_token']);
            $table->index(['check_id', 'run_ended_at', 'run_id']);
        });
        Schema::create('lighthouse_baselines', function (Blueprint $table): void {
            $table->foreignId('check_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->timestampTz('run_ended_at', 6);
            $table->jsonb('scores');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lighthouse_baselines');
        Schema::dropIfExists('lighthouse_reports');
    }
};
