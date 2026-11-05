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
            $table->dropUnique(['user_id']);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
        });
        DB::table('notification_destinations')->whereNotNull('user_id')->update(['is_primary' => true]);
        DB::statement('CREATE UNIQUE INDEX personal_primary_destination ON notification_destinations (user_id) WHERE is_primary AND user_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX personal_email_address ON notification_destinations (user_id, lower(config->>'email')) WHERE user_id IS NOT NULL AND channel = 'mail'");
    }

    public function down(): void
    {
        if (DB::table('notification_destinations')->whereNotNull('user_id')->where('is_primary', false)->exists()) {
            throw new LogicException('Remove secondary personal addresses before reverting this migration.');
        }
        DB::statement('DROP INDEX personal_email_address');
        DB::statement('DROP INDEX personal_primary_destination');
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['is_primary', 'verified_at']);
            $table->unique('user_id');
        });
    }
};
