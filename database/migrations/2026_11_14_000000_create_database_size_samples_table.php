<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_size_samples', static function (Blueprint $table): void {
            $table->id();
            $table->date('sampled_on')->unique();
            $table->timestampTz('sampled_at', 6);
            $table->unsignedBigInteger('total_bytes');
            // {table: {bytes, rows}}: total relation size and planner row estimate; partitions count under their parent.
            $table->jsonb('tables');
            // {data class: rows written in the 24 hours before sampled_at}.
            $table->jsonb('intake');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_size_samples');
    }
};
