<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** @var string|null $configuredTable */
        $configuredTable = config('settings.repositories.database.table');
        Schema::create($configuredTable ?? 'settings', function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');

            $table->timestampsTz(6);

            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        /** @var string|null $configuredTable */
        $configuredTable = config('settings.repositories.database.table');
        Schema::dropIfExists($configuredTable ?? 'settings');
    }
};
