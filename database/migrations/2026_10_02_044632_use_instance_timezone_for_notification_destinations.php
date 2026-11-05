<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->default(null)->change();
        });
        // Pre-release UTC defaults were not an operator's explicit timezone choice.
        DB::table('notification_destinations')->where('timezone', 'UTC')->update(['timezone' => null]);
    }

    public function down(): void
    {
        DB::table('notification_destinations')->whereNull('timezone')->update(['timezone' => 'UTC']);
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('timezone')->nullable(false)->default('UTC')->change();
        });
    }
};
