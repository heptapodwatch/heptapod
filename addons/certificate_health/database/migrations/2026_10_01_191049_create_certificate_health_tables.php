<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->uuid('claim_token');
            // Run partitions can be dropped without blocking addon snapshots.
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->jsonb('snapshot');
            $table->timestampTz('created_at', 6)->index();
            $table->unique(['run_id', 'claim_token']);
            $table->index(['check_id', 'run_ended_at', 'run_id']);
        });
        Schema::create('detected_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('issuer');
            $table->string('domain');
            $table->jsonb('additional_domains');
            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_until', 6);
            $table->string('signature_algorithm');
            $table->boolean('is_valid');
            $table->timestampsTz(6);
            $table->unique(['site_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detected_certificates');
        Schema::dropIfExists('certificate_health_snapshots');
    }
};
