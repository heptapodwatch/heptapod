<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->timestampTz('failing_since', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('failing_since');
        });
    }
};
