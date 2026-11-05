<?php

declare(strict_types=1);

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/NotificationChannels.php';

function notificationRemovalPath(): string
{
    return 'database/migrations/2026_10_07_125311_remove_site_notification_exceptions_and_secondary_personal_addresses.php';
}

// The upgrade boundary needs the old schema, not a production down() that cannot restore data.
function prepareNotificationRemovalUpgrade(): void
{
    // This upgrade ran before notification channels existed (2026-10-30); rebuild that schema first.
    restoreRoutingSchema();
    if (! Schema::hasTable('site_notification_preferences')) {
        Schema::create('site_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('mode')->default('inherit');
            $table->jsonb('weekdays')->nullable();
            $table->string('window_start', 5)->nullable();
            $table->string('window_end', 5)->nullable();
        });
        DB::statement("ALTER TABLE site_notification_preferences ADD CONSTRAINT site_notification_mode CHECK (mode IN ('inherit', 'business_hours'))");
        Schema::create('site_notification_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['site_id', 'started_at']);
        });
        Schema::create('site_notification_recipients', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('notification_destinations')->cascadeOnDelete();
            $table->primary(['site_id', 'destination_id']);
        });
        $historical = require database_path('migrations/2026_10_04_235655_allow_multiple_verified_personal_email_destinations.php');
        $historical->up();
    }
    DB::table('migrations')->where('migration', basename(notificationRemovalPath(), '.php'))->delete();
}

function notificationRemovalSnapshot(): array
{
    $tables = ['site_notification_preferences', 'site_notification_recipients', 'site_notification_mutes',
        'notification_destinations', 'users', 'deliveries', 'events', 'notification_rules', 'site_notification_follows', 'notification_rule_destination', 'migrations'];
    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
    }
    $snapshot['columns'] = DB::select('SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position');
    $snapshot['indexes'] = DB::select('SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() ORDER BY tablename, indexname');

    return $snapshot;
}

function runNotificationRemoval(): void
{
    Artisan::call('migrate', ['--path' => notificationRemovalPath(), '--force' => true, '--no-interaction' => true]);
}

it('drops only unused notification exception schema while preserving account identity and history', function (): void {
    prepareNotificationRemovalUpgrade();
    $user = User::factory()->create();
    $destination = legacyNotificationDestination(['user_id' => $user->id, 'is_primary' => true,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => strtoupper($user->email)]), 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'digest_enabled' => true]);
    $ruleId = DB::table('notification_rules')->insertGetId(['event_type' => 'check.warning', 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notification_rule_destination')->insert(['notification_rule_id' => $ruleId, 'notification_destination_id' => $destination->id]);
    $event = Event::factory()->create();
    DB::table('site_notification_follows')->insert(['site_id' => $event->site_id, 'user_id' => $user->id]);
    DB::table('deliveries')->insert(['event_id' => $event->id, 'destination_id' => $destination->id,
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $before = notificationRemovalSnapshot();

    runNotificationRemoval();

    foreach (['site_notification_preferences', 'site_notification_mutes', 'site_notification_recipients'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasColumn('notification_destinations', 'is_primary'))->toBeFalse()
        ->and(Schema::hasColumn('notification_destinations', 'verified_at'))->toBeFalse();
    $retained = (array) DB::table('notification_destinations')->where('id', $destination->id)->sole();
    $original = $before['notification_destinations'][0];
    unset($original['is_primary'], $original['verified_at']);
    expect($retained)->toBe($original);
    foreach (['users', 'deliveries', 'events', 'notification_rules', 'site_notification_follows', 'notification_rule_destination'] as $table) {
        expect(DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before[$table]);
    }
    expect(Schema::hasColumn('deliveries', 'digest_slot_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email_verified_at'))->toBeTrue();
    $indexes = array_column(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['notification_destinations']), 'indexname');
    expect($indexes)->toContain('notification_destinations_user_id_unique')
        ->not->toContain('personal_primary_destination', 'personal_email_address');
    expect(DB::table('migrations')->where('migration', basename(notificationRemovalPath(), '.php'))->exists())->toBeTrue();
});

it('rejects every legacy row and incompatible personal destination before writes with safe diagnostics', function (string $case, string $category): void {
    prepareNotificationRemovalUpgrade();
    $check = Check::factory()->create();
    $user = User::factory()->create(['email' => 'account@example.test']);
    $destination = legacyNotificationDestination(['user_id' => $user->id, 'is_primary' => true, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $user->email])]);
    match ($case) {
        'inherit' => DB::table('site_notification_preferences')->insert(['site_id' => $check->site_id, 'mode' => 'inherit']),
        'ended' => DB::table('site_notification_mutes')->insert(['site_id' => $check->site_id, 'started_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'ended_at' => now()->subDay()]),
        'disabled' => (function () use ($check, $destination): void {
            DB::table('notification_destinations')->where('id', $destination->id)->update(['enabled' => false]);
            DB::table('site_notification_recipients')->insert(['site_id' => $check->site_id, 'destination_id' => $destination->id]);
        })(),
        'all-tables' => (function () use ($check, $destination): void {
            DB::table('site_notification_preferences')->insert(['site_id' => $check->site_id, 'mode' => 'inherit']);
            DB::table('site_notification_mutes')->insert(['site_id' => $check->site_id, 'started_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'ended_at' => now()->subDay()]);
            DB::table('site_notification_recipients')->insert(['site_id' => $check->site_id, 'destination_id' => $destination->id]);
        })(),
        'extra' => DB::table('notification_destinations')->where('id', $destination->id)->update(['config' => json_encode(['email' => 'private-extra@example.test'])]),
        'override' => DB::table('notification_destinations')->where('id', $destination->id)->update(['config_secrets' => Crypt::encryptString(json_encode(['email' => 'secret-address@example.test']))]),
        'ciphertext' => DB::table('notification_destinations')->where('id', $destination->id)->update(['config_secrets' => 'secret-invalid-ciphertext']),
        'shape' => DB::table('notification_destinations')->where('id', $destination->id)->update(['config_secrets' => Crypt::encryptString('"secret-invalid-shape"')]),
        'invalid' => DB::table('notification_destinations')->where('id', $destination->id)->update(['config' => '{"email":42}']),
        'nonmail' => DB::table('notification_destinations')->where('id', $destination->id)->update(['channel' => 'webhook']),
        'duplicate' => legacyNotificationDestination(['user_id' => $user->id, 'is_primary' => false, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'duplicate@example.test'])]),
        'marker' => DB::table('notification_destinations')->where('id', $destination->id)->update(['is_primary' => false]),
        'verified-marker' => DB::table('notification_destinations')->where('id', $destination->id)->update(['verified_at' => now()]),
        'orphan' => (function () use ($destination): void {
            Schema::table('notification_destinations', fn (Blueprint $table) => $table->dropForeign(['user_id']));
            DB::table('notification_destinations')->where('id', $destination->id)->update(['user_id' => 999999]);
        })(),
    };
    $before = notificationRemovalSnapshot();
    DB::enableQueryLog();
    $message = null;
    try {
        runNotificationRemoval();
    } catch (LogicException $exception) {
        $message = $exception->getMessage();
        expect($exception->getPrevious())->toBeNull();
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($message)->toContain('Removal blocked:', $category.'=1')
        ->not->toContain('@', 'secret-', 'account@example.test');
    if ($case === 'all-tables') {
        expect($message)->toContain('site_notification_preferences=1', 'site_notification_recipients=1', 'site_notification_mutes=1');
    }
    expect(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(drop|alter|create|insert|update|delete)\b/i', $query['query']) === 1))->toBe([]);
    expect(notificationRemovalSnapshot())->toEqual($before);
})->with([
    'all inherit preferences' => ['inherit', 'site_notification_preferences'],
    'all ended mutes' => ['ended', 'site_notification_mutes'],
    'disabled recipients' => ['disabled', 'site_notification_recipients'],
    'all three tables counted together' => ['all-tables', 'site_notification_preferences'],
    'non-account public email' => ['extra', 'notification_destinations.non_account_personal'],
    'encrypted effective override' => ['override', 'notification_destinations.non_account_personal'],
    'invalid ciphertext' => ['ciphertext', 'notification_destinations.invalid_config'],
    'invalid decrypted shape' => ['shape', 'notification_destinations.invalid_config'],
    'invalid address' => ['invalid', 'notification_destinations.invalid_address'],
    'non-mail personal' => ['nonmail', 'notification_destinations.non_mail_personal'],
    'duplicate account destinations' => ['duplicate', 'notification_destinations.duplicate_personal'],
    'contradictory primary marker' => ['marker', 'notification_destinations.contradictory_marker'],
    'contradictory verification marker' => ['verified-marker', 'notification_destinations.contradictory_marker'],
    'orphan account reference' => ['orphan', 'notification_destinations.orphan_personal'],
]);

it('compares decrypted effective email without modifying valid public config or secrets', function (): void {
    prepareNotificationRemovalUpgrade();
    $user = User::factory()->create(['email' => 'account@example.test']);
    $destination = legacyNotificationDestination(['user_id' => $user->id, 'is_primary' => true, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'stale-public@example.test'])]);
    DB::table('notification_destinations')->where('id', $destination->id)->update(['config_secrets' => Crypt::encryptString('{"email":" ACCOUNT@example.test "}')]);
    $original = (array) DB::table('notification_destinations')->where('id', $destination->id)->sole();
    unset($original['is_primary'], $original['verified_at']);

    runNotificationRemoval();

    expect((array) DB::table('notification_destinations')->where('id', $destination->id)->sole())->toBe($original);
});

it('rolls back earlier drops and leaves the ledger unchanged when later DDL is blocked', function (): void {
    prepareNotificationRemovalUpgrade();
    DB::statement('CREATE VIEW notification_removal_dependency AS SELECT is_primary FROM notification_destinations');
    $before = notificationRemovalSnapshot();
    DB::enableQueryLog();

    expect(fn () => runNotificationRemoval())->toThrow(QueryException::class);

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    expect($queries)->toContain('drop table "site_notification_recipients"', 'drop table "site_notification_mutes"', 'drop table "site_notification_preferences"');
    expect(notificationRemovalSnapshot())->toEqual($before);
    DB::statement('DROP VIEW notification_removal_dependency');
});

it('requires the pre-upgrade backup for rollback instead of pretending to restore deleted metadata', function (): void {
    $migration = require base_path(notificationRemovalPath());

    $message = null;
    try {
        $migration->down();
    } catch (LogicException $exception) {
        $message = $exception->getMessage();
    }
    expect($message)->toBe('This migration removes site notification exceptions and secondary personal addresses. Roll back by restoring the pre-upgrade backup (scripts/upgrade.sh creates one).');
});
