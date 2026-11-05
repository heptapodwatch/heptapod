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
        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->change();
        });
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_site_required CHECK (site_id IS NOT NULL OR type = 'notification.digest')");
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('timezone')->default('UTC');
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->boolean('digest_enabled')->default(false);
            $table->string('digest_time', 5)->default('09:00');
        });
        Schema::create('notification_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->enum('scope_type', ['all', 'client', 'site', 'tag']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->boolean('mute_inherited')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE notification_rules ADD CONSTRAINT rules_scope CHECK ((scope_type = 'all') = (scope_id IS NULL) AND (NOT mute_inherited OR scope_type = 'site'))");
        Schema::create('notification_rule_destination', function (Blueprint $table): void {
            $table->foreignId('notification_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
            $table->primary(['notification_rule_id', 'notification_destination_id']);
        });
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('notification_destinations')->cascadeOnDelete();
            $table->enum('state', ['pending', 'sending', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', 6);
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('lease_until', 6)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->string('last_reason')->nullable();
            $table->text('last_diagnosis')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->boolean('suppressed')->default(false);
            $table->string('suppression_reason')->nullable();
            $table->timestampTz('suppression_evaluated_at', 6);
            $table->boolean('digest')->default(false);
            $table->foreignId('digest_event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->unique(['event_id', 'destination_id']);
            $table->index(['state', 'next_attempt_at']);
        });
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_suppression CHECK (suppressed = (suppression_reason IS NOT NULL) AND (NOT suppressed OR state <> \'sent\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('notification_rule_destination');
        Schema::dropIfExists('notification_rules');
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'quiet_start', 'quiet_end', 'digest_enabled', 'digest_time']);
        });
        DB::table('events')->where('type', 'notification.digest')->delete();
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_site_required');
        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable(false)->change();
        });
    }
};
