<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The open security advisories of each check's latest accepted run; replaced on every accepted run.
        Schema::create('application_health_advisories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->string('package', 255);
            // The CVE, or the advisory link when the advisory has no CVE.
            $table->string('advisory_id', 2048);
            $table->string('title', 1000);
            $table->string('link', 2048);
            $table->string('cve', 64)->nullable();
            $table->string('severity', 32)->nullable();
            $table->string('affected_versions', 1000);
            $table->timestampTz('reported_at');
            $table->index(['check_id', 'package']);
            $table->index(['package', 'check_id']);
            $table->index(['advisory_id', 'check_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_health_advisories');
    }
};
