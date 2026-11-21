<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', static function (Blueprint $table): void {
            $table->boolean('folded_with_recovery')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', static function (Blueprint $table): void {
            $table->dropColumn('folded_with_recovery');
        });
    }
};
