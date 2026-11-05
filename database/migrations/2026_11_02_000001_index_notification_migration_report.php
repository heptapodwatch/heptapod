<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The notification settings page reads the latest migration report or its dismissal (NotificationSettingsReader). Without
 * this index that lookup scans the whole audit log; the index holds only those one or two rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A plain build: activity_log is a few hundred rows on running instances, so its SHARE lock lasts milliseconds.
        DB::statement("CREATE INDEX activity_log_notification_migration_report ON activity_log (id)
            WHERE event IN ('notifications.migrated', 'notifications.migration_report_dismissed')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX activity_log_notification_migration_report');
    }
};
