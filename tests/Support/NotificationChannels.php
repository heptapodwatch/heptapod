<?php

declare(strict_types=1);

use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\NotificationSiteMute;
use Heptapod\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Routing setup for notification tests: channels with a category grid, the shape production writes. */
function notificationWebhook(string $locale = 'en'): NotificationDestination
{
    return NotificationDestination::factory()->create(['channel' => 'webhook', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://receiver.test/alerts', 'secret' => 'private-webhook-secret']),
    ]);
}

/**
 * Turn grid cells on for a channel, as the settings endpoint does: in one transaction with the reconciliation request.
 *
 * @param  list<NotificationCategory>  $categories
 */
function notificationRoute(NotificationDestination $channel, array $categories, NotificationMode $mode = NotificationMode::Immediate): NotificationDestination
{
    DB::transaction(static function () use ($channel, $categories, $mode): void {
        foreach ($categories as $category) {
            DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', $category->value)->update(['mode' => $mode->value]);
        }
        app(RequestRoutingReconciliation::class)->forChannel($channel);
    });

    return $channel;
}

/**
 * The grid cells that carry one routing key, as the migration converts an all-sites rule: check.failing feeds both
 * Failure and Check error, check.warning also carries domain expiry notices.
 */
function notificationRule(NotificationDestination $channel, string $key = 'site.down', NotificationMode $mode = NotificationMode::Immediate): NotificationDestination
{
    $categories = match (true) {
        $key === 'site.down' => [NotificationCategory::Outage],
        $key === 'check.failing' => [NotificationCategory::Failure, NotificationCategory::CheckError],
        $key === 'check.warning' => [NotificationCategory::Warning, NotificationCategory::Expiring],
        $key === 'certificate.expiring' => [NotificationCategory::Expiring],
        in_array($key, ['site.recovered', 'check.recovered'], true) => [NotificationCategory::Recovery],
        in_array($key, NotificationCategory::ChangeKeys, true) => [NotificationCategory::Changes],
        default => throw new LogicException('No grid category carries '.$key.'.'),
    };
    if ($mode === NotificationMode::Digest) {
        $categories = array_values(array_filter($categories, static fn (NotificationCategory $category): bool => $category->allowsDigest()));
    }

    return notificationRoute($channel, $categories, $mode);
}

/** The user's account channel with exactly these categories on. */
function notificationAccountChannel(User $user, array $categories): NotificationDestination
{
    $channel = app(EnsureAccountChannel::class)($user);
    NotificationPreference::writeGrid($channel->id, array_map(static fn (): NotificationMode => NotificationMode::Off, NotificationPreference::defaults($channel->recipient_kind)));

    return notificationRoute($channel, $categories);
}

function notificationMuteSite(NotificationDestination $channel, int $siteId): void
{
    NotificationSiteMute::query()->create(['destination_id' => $channel->id, 'site_id' => $siteId, 'created_at' => now()]);
}

/** @param array<string, mixed> $attributes */
function notificationSchedule(NotificationDestination $channel, array $attributes): NotificationSchedule
{
    $schedule = NotificationSchedule::forChannel($channel);
    $schedule->fill($attributes)->save();

    return $schedule;
}

function dispatchNotifications(): void
{
    app()->call(new DispatchNotifications()->handle(...));
}

/** A recorded recovery references its accepted good run, as the recorder's outbox payload does (d4023e31). */
function notificationRecoveryEvent(Event $opened, array $attributes = []): Event
{
    $check = $opened->check ?? throw new LogicException('A recovery fixture needs its check.');
    $run = CheckRun::factory()->for($check)->create(['started_at' => now()->subSecond(), 'ended_at' => now(),
        'summary' => $check->type->value.'.succeeded']);

    return Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id,
        'incident_id' => $opened->incident_id, 'type' => 'incident.resolved', 'cause' => 'failed',
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded,
        'payload' => ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP')], ...$attributes]);
}

function notificationIncidentEvent(array $attributes = []): Event
{
    // Opening alerts render from the incident's retained opening snapshot (IncidentTransitions), never from the latest run (d4023e31).
    $incident = Incident::factory()->create(['evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503],
        'evidence' => [], 'metrics' => null, 'error' => null, 'run_ended_at' => now()->format('Y-m-d\TH:i:s.uP')]]);

    return Event::factory()->create(['site_id' => $incident->site_id, 'check_id' => $incident->check_id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed, ...$attributes]);
}

function unifyRecipientsPath(): string
{
    return 'database/migrations/2026_10_30_000001_unify_notification_recipients.php';
}

/** The schema of 2026-10-27 (f906f3a9): rules, follows and per-destination noise columns, before the conversion. */
function restoreRoutingSchema(): void
{
    DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_origin');
    DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_category');
    Schema::table('deliveries', function (Blueprint $table): void {
        $table->dropIndex(['destination_id', 'id']);
        $table->dropConstrainedForeignId('actor_id');
        $table->dropColumn(['origin', 'actor_name', 'category', 'recipient_snapshot']);
        $table->boolean('rule_digest')->default(false);
        $table->dropForeign(['destination_id']);
        $table->foreign('destination_id')->references('id')->on('notification_destinations')->cascadeOnDelete();
    });
    Schema::drop('notification_schedules');
    Schema::drop('notification_site_mutes');
    Schema::drop('notification_preferences');
    foreach (['recipient', 'account_mail', 'scope_client', 'removed'] as $check) {
        DB::statement('ALTER TABLE notification_destinations DROP CONSTRAINT notification_destinations_'.$check);
    }
    DB::statement('DROP INDEX notification_destinations_account_mail_user');
    DB::table('notification_destinations')->delete();
    Schema::table('notification_destinations', function (Blueprint $table): void {
        $table->dropIndex(['recipient_kind', 'client_id']);
        $table->dropConstrainedForeignId('client_id');
        $table->dropConstrainedForeignId('scope_client_id');
        $table->dropColumn(['recipient_kind', 'account_mail', 'removed_at', 'removed_reason']);
        $table->string('locale', 2)->default('en')->nullable(false)->change();
        $table->jsonb('notification_types')->nullable();
        $table->string('scope_type')->nullable();
        $table->unsignedBigInteger('scope_id')->nullable();
        $table->string('timezone')->nullable();
        $table->string('quiet_start', 5)->nullable();
        $table->string('quiet_end', 5)->nullable();
        $table->boolean('digest_enabled')->default(false);
        $table->string('digest_time', 5)->default('08:00');
        $table->boolean('quiet_weekends')->default(false);
        $table->jsonb('digest_weekdays')->nullable();
        $table->dropForeign(['user_id']);
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        $table->unique('user_id');
    });
    Schema::create('notification_rules', function (Blueprint $table): void {
        $table->id();
        $table->string('event_type');
        $table->enum('scope_type', ['all', 'client', 'site', 'tag']);
        $table->unsignedBigInteger('scope_id')->nullable();
        $table->boolean('mute_inherited')->default(false);
        $table->timestampsTz();
        $table->boolean('digest')->default(false);
        $table->jsonb('weekdays')->nullable();
        $table->string('window_start', 5)->nullable();
        $table->string('window_end', 5)->nullable();
    });
    DB::statement("ALTER TABLE notification_rules ADD CONSTRAINT rules_scope CHECK ((scope_type = 'all') = (scope_id IS NULL) AND (NOT mute_inherited OR scope_type = 'site'))");
    Schema::create('notification_rule_destination', function (Blueprint $table): void {
        $table->foreignId('notification_rule_id')->constrained()->cascadeOnDelete();
        $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
        $table->primary(['notification_rule_id', 'notification_destination_id']);
    });
    Schema::create('site_notification_follows', function (Blueprint $table): void {
        $table->foreignId('site_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->primary(['site_id', 'user_id']);
    });
    DB::table('activity_log')->where('event', 'notifications.migrated')->delete();
    DB::table('migrations')->where('migration', basename(unifyRecipientsPath(), '.php'))->delete();
    // The conversion reads the Admin opt-in that 2026_10_30_000002 later drops.
    Schema::table('users', function (Blueprint $table): void {
        $table->boolean('system_issue_mails')->default(false);
    });
}

/**
 * A destination row in the pre-2026-10-30 schema (after restoreRoutingSchema), written as the old code wrote it.
 *
 * @param  array<string, mixed>  $attributes
 */
function legacyNotificationDestination(array $attributes): stdClass
{
    $config = $attributes['config'] ?? app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => fake()->safeEmail()]);
    $row = ['label' => fake()->word(), 'channel' => 'mail', 'locale' => 'en', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ...array_diff_key($attributes, ['config' => true]),
        'config' => json_encode((object) $config->public, JSON_THROW_ON_ERROR),
        'config_secrets' => $config->secrets() === [] ? null : Crypt::encryptString(json_encode($config->secrets(), JSON_THROW_ON_ERROR))];
    $id = DB::table('notification_destinations')->insertGetId($row);

    return DB::table('notification_destinations')->where('id', $id)->sole();
}
