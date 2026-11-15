<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_repositories', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // The canonical https://host/path form of RepositoryUrl; the reverse lookup filters on it.
            $table->string('url', 2048)->index();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['site_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_repositories');
    }
};
