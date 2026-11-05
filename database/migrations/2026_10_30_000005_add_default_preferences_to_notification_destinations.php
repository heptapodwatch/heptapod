<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each channel's baseline grid: the cells its creating action wrote, so RecipientSettings can mark changed rows and reset
 * them. Who created a channel (a first Owner, a preconfigured install) cannot be derived later. Channels that exist now
 * keep the grid the 2026-10-30 conversion gave them as their baseline, so resetting never undoes that conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->jsonb('default_preferences')->nullable();
        });
        DB::statement(<<<'SQL'
            UPDATE notification_destinations d SET default_preferences = cells.grid
              FROM (SELECT destination_id, jsonb_object_agg(category, mode) AS grid FROM notification_preferences GROUP BY destination_id) cells
             WHERE cells.destination_id = d.id
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
    }
};
