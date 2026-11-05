<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_baseline_generations', function (Blueprint $table): void {
            $table->foreignId('check_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('generation')->default(0);
        });
        Schema::table('dns_observations', function (Blueprint $table): void {
            $table->bigInteger('baseline_generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dns_observations', function (Blueprint $table): void {
            $table->dropColumn('baseline_generation');
        });
        Schema::dropIfExists('dns_baseline_generations');
    }
};
