<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One import from an external account: plan snapshot, assignments, attempts and results. Never a token.
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source');
            $table->string('status');
            $table->unsignedInteger('expected');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('teams');
            $table->jsonb('plan');
            $table->jsonb('assignments')->nullable();
            $table->jsonb('results');
            $table->jsonb('created_client_ids');
            $table->text('remote_error')->nullable();
            $table->string('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
