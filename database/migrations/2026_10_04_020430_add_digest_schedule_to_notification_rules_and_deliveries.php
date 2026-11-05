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
        DB::table('notification_destinations')->update(['digest_time' => '08:00']);
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('digest_time', 5)->default('08:00')->change();
        });
        Schema::table('notification_rules', function (Blueprint $table): void {
            $table->boolean('digest')->default(false);
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->boolean('rule_digest')->default(false);
            $table->date('digest_local_date')->nullable();
            $table->unique(['destination_id', 'digest_local_date']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('digest_time', 5)->default('09:00')->change();
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique(['destination_id', 'digest_local_date']);
            $table->dropColumn(['rule_digest', 'digest_local_date']);
        });
        Schema::table('notification_rules', function (Blueprint $table): void {
            $table->dropColumn('digest');
        });
    }
};
