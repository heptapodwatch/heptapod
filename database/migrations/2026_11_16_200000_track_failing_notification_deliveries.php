<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery that keeps failing gives up after a day (CompleteNotificationDelivery), and a channel that keeps failing opens a
 * channel_failing system issue. The due index serves the dispatcher's scan and the notifications_stalled check, which both
 * skip the growing set of suppressed and finished rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->timestampTz('first_failed_at', 6)->nullable();
        });
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->timestampTz('failing_since', 6)->nullable();
        });
        DB::statement("CREATE INDEX deliveries_due ON deliveries (next_attempt_at, id) WHERE NOT suppressed AND NOT digest AND state IN ('pending', 'failed')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX deliveries_due');
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn('failing_since');
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('first_failed_at');
        });
    }
};
