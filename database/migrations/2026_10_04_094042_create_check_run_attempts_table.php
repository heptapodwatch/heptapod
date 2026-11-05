<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_run_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->string('claim_token', 36)->unique();
            $table->string('phase', 16);
            $table->string('queue', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at', 6);
            $table->timestampTz('due_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->unsignedInteger('progress_done')->nullable();
            $table->unsignedInteger('progress_total')->nullable();
            $table->string('phase_label', 64)->nullable();
            $table->unsignedBigInteger('check_run_id')->nullable();
            $table->timestampTz('cancel_requested_at', 6)->nullable();
            $table->string('note', 128)->nullable();
            $table->index(['phase', 'requested_at']);
            $table->index(['check_id', 'id']);
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_run_attempts');
    }
};
