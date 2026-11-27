<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // Only what differs from the instance defaults (config servers.thresholds), plus the ignored mounts.
            $table->jsonb('settings')->default('{}');
        });

        // "Runs on": one site runs on one server, so linking a site again moves it.
        Schema::create('server_sites', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('server_id')->index()->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('created_at', 6);
        });
    }
};
