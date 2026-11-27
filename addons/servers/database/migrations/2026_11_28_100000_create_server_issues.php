<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server alerts. Everything here is the servers addon's except the shared tables they reach the team through, which a
 * removal reverts: events (server_issue_id, server_name, events_site_required, events_server_issue_phase) and the
 * Servers notification category (its notification_preferences rows and the category CHECKs of notification_preferences
 * and deliveries).
 */
return new class extends Migration
{
    private const array Categories = ['outage', 'failure', 'check_error', 'warning', 'expiring', 'recovery', 'changes', 'system', 'servers', 'report'];

    private const array Digestible = ['check_error', 'warning', 'expiring', 'changes', 'servers'];

    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // Alerts wait while snoozed (the snooze ends at this instant); the server keeps reporting and its issues keep opening.
            $table->timestampTz('snoozed_until', 6)->nullable();
        });

        Schema::create('server_issues', function (Blueprint $table): void {
            $table->id();
            // Kept after the server is removed, as its alert history: the events keep the server's name.
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('kind', 32);
            // The mount for disk_full; empty for the other kinds.
            $table->text('scope')->default('');
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('resolved_at', 6)->nullable();
            // The value, threshold and duration the issue opened on, and the value it resolved on (or why it ended).
            $table->jsonb('opened_values');
            $table->jsonb('resolved_values')->nullable();
        });
        DB::statement("ALTER TABLE server_issues ADD CONSTRAINT server_issues_kind CHECK (kind IN ('disk_full', 'memory_low', 'load_high', 'no_report'))");
        DB::statement("ALTER TABLE server_issues ADD CONSTRAINT server_issues_scope CHECK ((kind = 'disk_full') = (scope <> ''))");
        // One open issue per server, kind and scope; also the index every "open issues of this server" read uses.
        DB::statement('CREATE UNIQUE INDEX server_issues_one_open ON server_issues (server_id, kind, scope) WHERE resolved_at IS NULL');

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('server_issue_id')->nullable()->constrained('server_issues')->restrictOnDelete();
            $table->string('server_name')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX events_server_issue_phase ON events (server_issue_id, type) WHERE server_issue_id IS NOT NULL');
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (type = 'notification.digest'"
            ." OR (type IN ('system.issue_opened', 'system.issue_resolved') AND system_issue_id IS NOT NULL AND site_id IS NULL)"
            ." OR (type IN ('server.issue_opened', 'server.issue_resolved') AND server_issue_id IS NOT NULL AND site_id IS NULL AND server_name IS NOT NULL)"
            .' OR (site_name IS NOT NULL AND site_url IS NOT NULL AND client_name IS NOT NULL AND client_id IS NOT NULL))');

        $categories = "'".implode("', '", self::Categories)."'";
        $digestible = "'".implode("', '", self::Digestible)."'";
        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_category');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_category CHECK (category IN ({$categories}))");
        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_mode');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_mode CHECK (mode IN ('immediate', 'digest', 'off') AND (mode <> 'digest' OR category IN ({$digestible})))");
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_category');
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_category CHECK (category IS NULL OR category IN ({$categories}))");

        // Every channel has one cell per category (NotificationPreference). Personal channels of Owners and Admins start on,
        // every other channel off (NotificationPreference::defaults); the cell joins the channel's baseline too.
        $mode = "CASE WHEN d.recipient_kind = 'user' AND EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
            WHERE m.model_type = 'user' AND m.model_id = d.user_id AND r.name IN ('Owner', 'Admin')) THEN 'immediate' ELSE 'off' END";
        DB::statement("INSERT INTO notification_preferences (destination_id, category, mode) SELECT d.id, 'servers', {$mode}
            FROM notification_destinations d ON CONFLICT (destination_id, category) DO NOTHING");
        DB::statement("UPDATE notification_destinations d SET default_preferences = d.default_preferences || jsonb_build_object('servers', {$mode})
            WHERE d.default_preferences IS NOT NULL AND NOT jsonb_exists(d.default_preferences, 'servers')");
    }
};
