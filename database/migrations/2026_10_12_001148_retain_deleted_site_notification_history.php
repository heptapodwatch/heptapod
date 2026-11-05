<?php

declare(strict_types=1);

use Heptapod\Contracts\SecretRedactor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['events', 'deliveries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('site_name')->nullable();
                $table->text('site_url')->nullable();
                $table->text('client_name')->nullable();
                // Retained scope, not a live relation: deleting a client must not erase audit identity.
                $table->unsignedBigInteger('client_id')->nullable();
                $table->string('check_type')->nullable();
            });
        }
        DB::statement("UPDATE events SET site_name = coalesce(nullif(sites.friendly_name, ''), rtrim(regexp_replace(regexp_replace(sites.url, '[?#].*$', ''), '^[[:alpha:]][[:alnum:]+.-]*://([^/@?#]*@)?', ''), '/')), client_name = clients.name, client_id = sites.client_id FROM sites JOIN clients ON clients.id = sites.client_id WHERE events.site_id = sites.id");
        foreach (DB::table('sites')->select(['id', 'url'])->lazyById(100) as $site) {
            /** @var stdClass&object{id: int, url: string} $site */
            DB::table('events')->where('site_id', $site->id)->update(['site_url' => SecretRedactor::redact($site->url, [], [$site->url])]);
        }
        DB::statement('UPDATE events SET check_type = checks.type FROM checks WHERE events.check_id = checks.id');
        DB::statement('UPDATE deliveries SET site_name = events.site_name, site_url = events.site_url, client_name = events.client_name, client_id = events.client_id, check_type = events.check_type FROM events WHERE deliveries.event_id = events.id');
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (type = 'notification.digest' OR (site_name IS NOT NULL AND site_url IS NOT NULL AND client_name IS NOT NULL AND client_id IS NOT NULL))");
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_valid_subject');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_valid_subject CHECK ((component_type IS NULL AND component_id IS NULL) OR ((check_id IS NOT NULL OR check_type IS NOT NULL) AND component_type IS NOT NULL AND component_id IS NOT NULL AND component_id > 0))');
        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropForeign(['check_id']);
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('check_id')->references('id')->on('checks')->nullOnDelete();
            $table->index(['client_id', 'occurred_at', 'id']);
            $table->index(['occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        // Old schema cannot represent deleted-site history. Refuse rather than cascade away retained deliveries.
        if (DB::table('events')->whereNull('site_id')->where('type', '<>', 'notification.digest')->exists()
            || DB::table('events')->whereNull('check_id')->whereNotNull('component_type')->exists()) {
            throw new RuntimeException('Cannot roll back while detached notification history exists. Restore a pre-deletion backup or keep this migration.');
        }
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_valid_subject');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_valid_subject CHECK ((component_type IS NULL AND component_id IS NULL) OR (check_id IS NOT NULL AND component_type IS NOT NULL AND component_id IS NOT NULL AND component_id > 0))');
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (site_id IS NOT NULL OR type = 'notification.digest')");
        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropForeign(['check_id']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('check_id')->references('id')->on('checks')->cascadeOnDelete();
            $table->dropIndex(['client_id', 'occurred_at', 'id']);
            $table->dropIndex(['occurred_at', 'id']);
        });
        // Rollback discards snapshot identity; re-applying backfills current names. Redacted secrets cannot be restored.
        foreach (['deliveries', 'events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['site_name', 'site_url', 'client_name', 'client_id', 'check_type']));
        }
    }
};
