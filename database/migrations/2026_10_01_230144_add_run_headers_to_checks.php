<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->text('run_headers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('run_headers');
        });
    }
};
