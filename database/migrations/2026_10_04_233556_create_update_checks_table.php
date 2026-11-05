<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('latest_version')->nullable();
            $table->string('release_url')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_checks');
    }
};
