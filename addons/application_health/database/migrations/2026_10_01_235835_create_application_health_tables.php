<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_health_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 64);
            $table->boolean('active')->default(false);
            $table->jsonb('snapshot')->nullable();
            $table->string('change_identity', 64)->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->timestampTz('run_ended_at', 6)->nullable();
            $table->timestampTz('detected_at', 6);
            $table->timestampTz('updated_at', 6)->nullable();
            $table->unique(['check_id', 'source_key']);
        });
        Schema::create('application_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('run_id');
            $table->uuid('claim_token');
            // Raw partitions may disappear without deleting stable component identities.
            $table->timestampTz('run_ended_at', 6)->nullable()->index();
            $table->jsonb('components');
            $table->timestampTz('created_at', 6);
            $table->unique(['run_id', 'claim_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_health_snapshots');
        Schema::dropIfExists('application_health_components');
    }
};
