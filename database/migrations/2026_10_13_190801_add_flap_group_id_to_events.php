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
        Schema::table('events', static function (Blueprint $table): void {
            $table->unsignedBigInteger('flap_group_id')->nullable();
        });
        DB::statement('CREATE INDEX events_flap_members ON events (flap_group_id, occurred_at DESC, id DESC) WHERE flap_group_id IS NOT NULL');
        DB::statement('CREATE INDEX events_check_history ON events (check_id, occurred_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX events_check_history');
        DB::statement('DROP INDEX events_flap_members');
        Schema::table('events', static function (Blueprint $table): void {
            $table->dropColumn('flap_group_id');
        });
    }
};
