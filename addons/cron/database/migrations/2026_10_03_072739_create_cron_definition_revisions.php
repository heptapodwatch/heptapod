<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_definition_revisions', function (Blueprint $table): void {
            $table->foreignId('check_id')->primary()->constrained('checks')->cascadeOnDelete();
            $table->bigInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_definition_revisions');
    }
};
