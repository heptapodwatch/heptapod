<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every channel has one cell per category (NotificationPreference): the Servers cell for every existing channel, on for
 * personal channels of Owners and Admins and off for every other channel (NotificationPreference::defaults), and the
 * same value in the channel's baseline. Outside any DDL transaction and in batches of channels, so no statement holds
 * the notification tables for long; rerunnable (existing cells and baseline keys are kept).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const int Batch = 500;

    private const string Mode = "CASE WHEN d.recipient_kind = 'user' AND EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
        WHERE m.model_type = 'user' AND m.model_id = d.user_id AND r.name IN ('Owner', 'Admin')) THEN 'immediate' ELSE 'off' END";

    public function up(): void
    {
        $this->inBatches(static function (string $ids): void {
            DB::statement("INSERT INTO notification_preferences (destination_id, category, mode) SELECT d.id, 'servers', ".self::Mode.'
                FROM notification_destinations d WHERE d.id = ANY(?::bigint[]) ON CONFLICT (destination_id, category) DO NOTHING', [$ids]);
            DB::statement("UPDATE notification_destinations d SET default_preferences = d.default_preferences || jsonb_build_object('servers', ".self::Mode.")
                WHERE d.id = ANY(?::bigint[]) AND d.default_preferences IS NOT NULL AND NOT jsonb_exists(d.default_preferences, 'servers')", [$ids]);
        });
    }

    public function down(): void
    {
        $this->inBatches(static function (string $ids): void {
            DB::statement("DELETE FROM notification_preferences WHERE category = 'servers' AND destination_id = ANY(?::bigint[])", [$ids]);
            DB::statement("UPDATE notification_destinations SET default_preferences = default_preferences - 'servers'
                WHERE id = ANY(?::bigint[]) AND jsonb_exists(default_preferences, 'servers')", [$ids]);
        });
    }

    /** @param Closure(string): void $batch called with each batch of channel ids as a Postgres array literal */
    private function inBatches(Closure $batch): void
    {
        $after = 0;
        do {
            /** @var list<int> $ids */
            $ids = DB::table('notification_destinations')->where('id', '>', $after)->orderBy('id')->limit(self::Batch)->pluck('id')->all();
            if ($ids !== []) {
                $batch('{'.implode(',', $ids).'}');
                $after = array_last($ids);
            }
        } while (count($ids) === self::Batch);
    }
};
