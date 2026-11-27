<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The second, non-transactional half of 2026_11_28_100000: the index on events is built CONCURRENTLY and the NOT VALID
 * constraints are validated, which scans the shared tables without blocking their writes. The recurring digest index keeps
 * the daily digest read to the items still recurring (a resolved server issue ends its recurrence), not a channel's history.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const array Validated = [
        'events' => ['events_server_issue_id_foreign', 'events_site_required'],
        'notification_preferences' => ['notification_preferences_category', 'notification_preferences_mode'],
        'deliveries' => ['deliveries_category'],
    ];

    private const array Indexes = [
        'events_server_issue_phase' => 'CREATE UNIQUE INDEX CONCURRENTLY events_server_issue_phase ON events (server_issue_id, type) WHERE server_issue_id IS NOT NULL',
        'deliveries_recurring_digest' => 'CREATE INDEX CONCURRENTLY deliveries_recurring_digest ON deliveries (destination_id, id) WHERE digest AND NOT suppressed AND digest_recurrence_ended_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::Indexes as $name => $create) {
            // A concurrent build that failed leaves an invalid index of the same name; drop it so a rerun builds it again.
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement($create);
        }
        foreach (self::Validated as $table => $constraints) {
            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::Indexes) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
