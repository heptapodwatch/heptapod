<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            // The id inside the agent token (hpa_<public_id>_<secret>); the secret is stored only as its SHA-256.
            $table->string('public_id', 26)->unique();
            $table->string('name');
            $table->char('token_hash', 64);
            // Reserved for a later, explicit Owner assignment; unused while servers are team-only.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestampTz('last_report_at', 6)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->string('agent_os', 32)->nullable();
            $table->string('agent_arch', 32)->nullable();
            $table->string('agent_distro', 64)->nullable();
            $table->string('agent_kernel', 128)->nullable();
            $table->string('hostname', 253)->nullable();
            $table->jsonb('ips')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('server_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->uuid('sample_id');
            $table->timestampTz('observed_at', 6);
            $table->timestampTz('received_at', 6);
            $table->jsonb('metrics');
            $table->unique(['server_id', 'sample_id']);
            $table->index(['server_id', 'observed_at']);
        });
    }
};
