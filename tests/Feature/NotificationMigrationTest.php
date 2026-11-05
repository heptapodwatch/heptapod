<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Events\Notifications\RoutingContext;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T09:00:00Z'));
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
});

/** @param array<string, mixed> $attributes */
function oldDestination(string $email, array $attributes = []): int
{
    $config = app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $email]);

    return DB::table('notification_destinations')->insertGetId([
        'label' => $attributes['label'] ?? $email, 'channel' => 'mail', 'locale' => 'en', 'enabled' => true,
        'config' => json_encode((object) $config->public, JSON_THROW_ON_ERROR),
        'config_secrets' => $config->secrets() === [] ? null : Crypt::encryptString(json_encode($config->secrets(), JSON_THROW_ON_ERROR)),
        'created_at' => now(), 'updated_at' => now(), ...array_diff_key($attributes, ['label' => true]),
    ]);
}

/** @param list<int> $destinations
 * @param  array<string, mixed>  $attributes
 */
function oldRule(string $type, array $destinations, array $attributes = []): int
{
    $id = DB::table('notification_rules')->insertGetId(['event_type' => $type, 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now(), ...$attributes]);
    foreach ($destinations as $destination) {
        DB::table('notification_rule_destination')->insert(['notification_rule_id' => $id, 'notification_destination_id' => $destination]);
    }

    return $id;
}

function runUnifyRecipients(): void
{
    expect(Artisan::call('migrate', ['--path' => unifyRecipientsPath(), '--force' => true, '--no-interaction' => true]))->toBe(0);
}

/** @return array<string, string> */
function gridOf(int $channelId): array
{
    /** @var array<string, string> */
    return DB::table('notification_preferences')->where('destination_id', $channelId)->orderBy('category')->pluck('mode', 'category')->all();
}

/** @param array<string, string> $on */
function expectedGrid(array $on): array
{
    $grid = array_fill_keys(['changes', 'check_error', 'expiring', 'failure', 'outage', 'recovery', 'system', 'warning'], 'off');
    ksort($grid);

    return [...$grid, ...$on];
}

/** @return list<string> */
function migrationReport(): array
{
    $properties = DB::table('activity_log')->where('event', 'notifications.migrated')->sole()->properties;

    return json_decode((string) $properties, true, flags: JSON_THROW_ON_ERROR)['lines'];
}

it('converts the production shape exactly and keeps channel ids, history and the preview identical to the report', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'owner@example.test', 'timezone' => 'Europe/Berlin']);
    $check = Check::factory()->create();
    restoreRoutingSchema();
    // Production shape seen in 2026-10: one team mail destination with four
    // all-site rules, the Owner's personal account destination without rules or follows, rule id 3 deleted earlier.
    DB::table('notification_destinations')->insert(['id' => 1, 'label' => 'Team', 'channel' => 'mail', 'locale' => 'en', 'enabled' => true,
        'config' => '{"email":"team@example.test"}', 'digest_time' => '08:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notification_destinations')->insert(['id' => 2, 'user_id' => $owner->id, 'label' => $owner->name, 'channel' => 'mail', 'locale' => 'en', 'enabled' => true,
        'config' => '{"email":"owner@example.test"}', 'timezone' => 'Europe/Berlin', 'digest_time' => '08:00', 'digest_weekdays' => '[1,2,3,4,5]',
        'created_at' => now(), 'updated_at' => now()]);
    DB::statement("SELECT setval('notification_destinations_id_seq', 2)");
    foreach ([1 => 'site.down', 2 => 'site.recovered', 4 => 'check.failing', 5 => 'check.recovered'] as $id => $type) {
        DB::table('notification_rules')->insert(['id' => $id, 'event_type' => $type, 'scope_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_rule_destination')->insert(['notification_rule_id' => $id, 'notification_destination_id' => 1]);
    }
    $event = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    $delivery = DB::table('deliveries')->insertGetId(['event_id' => $event->id, 'destination_id' => 1, 'state' => 'sent', 'sent_at' => now(),
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    // The preflight command prints this plan from a read-only transaction (tests/Deployment/upgrade.sh drives it).
    $preview = (require base_path(unifyRecipientsPath()))->plan()['lines'];

    runUnifyRecipients();

    $channels = DB::table('notification_destinations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    expect(count($channels))->toBe(2);
    expect(array_intersect_key($channels[0], array_flip(['id', 'recipient_kind', 'user_id', 'account_mail', 'scope_client_id', 'locale', 'enabled', 'config'])))
        ->toBe(['id' => 1, 'locale' => 'en', 'enabled' => true, 'config' => '{"email": "team@example.test"}', 'user_id' => null,
            'recipient_kind' => 'team', 'scope_client_id' => null, 'account_mail' => false]);
    expect(array_intersect_key($channels[1], array_flip(['id', 'recipient_kind', 'user_id', 'account_mail', 'locale', 'config'])))
        ->toBe(['id' => 2, 'locale' => null, 'config' => '{}', 'user_id' => $owner->id, 'recipient_kind' => 'user', 'account_mail' => true]);
    // site.down + check.failing (Failed and Check error) + both recoveries; nothing else was routed.
    expect(gridOf(1))->toBe(expectedGrid(['outage' => 'immediate', 'failure' => 'immediate', 'check_error' => 'immediate', 'recovery' => 'immediate']));
    // The personal destination had no rule and no follow: alerts stay off. The Owner keeps system issue mail.
    expect(gridOf(2))->toBe(expectedGrid(['system' => 'immediate']));
    expect(DB::table('notification_site_mutes')->count())->toBe(0);
    expect(DB::table('notification_schedules')->orderBy('recipient_kind')->get(['recipient_kind', 'user_id', 'quiet_start', 'quiet_end', 'quiet_weekends', 'digest_time', 'digest_weekdays'])
        ->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['recipient_kind' => 'team', 'user_id' => null, 'quiet_start' => null, 'quiet_end' => null, 'quiet_weekends' => false, 'digest_time' => '08:00', 'digest_weekdays' => null],
            ['recipient_kind' => 'user', 'user_id' => $owner->id, 'quiet_start' => null, 'quiet_end' => null, 'quiet_weekends' => false, 'digest_time' => '08:00', 'digest_weekdays' => '[1, 2, 3, 4, 5]'],
        ]);
    expect((array) DB::table('deliveries')->where('id', $delivery)->first(['destination_id', 'state', 'origin', 'category']))
        ->toBe(['destination_id' => 1, 'state' => 'sent', 'origin' => 'system', 'category' => 'outage']);
    // Exact conversion: no broadened category, dropped key, window, mute or schedule change is reported.
    expect(migrationReport())->toBe([
        'Channel #1 (team): outage=immediate, failure=immediate, check_error=immediate, warning=off, expiring=off, recovery=immediate, changes=off, system=off; 0 muted sites.',
        'Channel #2 (user, account email): outage=off, failure=off, check_error=off, warning=off, expiring=off, recovery=off, changes=off, system=immediate; 0 muted sites.',
    ]);
    expect($preview)->toBe(migrationReport());
    foreach (['notification_rules', 'notification_rule_destination', 'site_notification_follows'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    foreach (['scope_type', 'notification_types', 'timezone', 'quiet_start', 'digest_enabled', 'digest_weekdays'] as $column) {
        expect(Schema::hasColumn('notification_destinations', $column))->toBeFalse();
    }
    expect(Schema::hasColumn('deliveries', 'rule_digest'))->toBeFalse();
});

it('converts follows, personal digests, type filters, scopes, windows and team schedules into grids, mutes and schedules with every change reported', function (): void {
    $member = User::factory()->member()->create(['email' => 'member@example.test', 'timezone' => 'America/New_York', 'locale' => 'de']);
    $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $quiet = User::factory()->admin()->create(['email' => 'quiet@example.test']);
    [$acme, $beta] = [Client::factory()->create(), Client::factory()->create()];
    $internal = Client::query()->where('is_internal', true)->sole();
    $acmeSites = Site::factory()->count(2)->create(['client_id' => $acme->id]);
    $betaSite = Site::factory()->create(['client_id' => $beta->id]);
    $taggedSite = Site::factory()->create(['client_id' => $internal->id]);
    $taggedSite->attachTag('vip');
    $allSites = Site::query()->orderBy('id')->pluck('id')->all();
    restoreRoutingSchema();
    DB::table('users')->where('id', $admin->id)->update(['system_issue_mails' => true]);
    $tag = DB::table('tags')->value('id');

    // Member: follows one site, personal digest with quiet hours, stored timezone differs from the account.
    $personal = oldDestination('MEMBER@example.test', ['user_id' => $member->id, 'locale' => 'en', 'timezone' => 'Europe/Berlin',
        'quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_weekends' => true, 'digest_enabled' => true, 'digest_time' => '09:30', 'digest_weekdays' => '[1,3,5]']);
    DB::table('site_notification_follows')->insert(['site_id' => $acmeSites[0]->id, 'user_id' => $member->id]);
    // Admin: personal destination on a separate address, opted into system mail, one rule-digest on check.failing.
    $adminAddress = oldDestination('alerts-admin@example.test', ['user_id' => $admin->id]);
    oldRule('check.failing', [$adminAddress], ['digest' => true]);
    // Team A: one client, warning digest via the destination overlay, a type filter that drops recoveries.
    $teamClient = oldDestination('acme@example.test', ['notification_types' => '["site.down","check.warning"]', 'digest_enabled' => true,
        'quiet_start' => '20:00', 'quiet_end' => '06:00']);
    oldRule('site.down', [$teamClient], ['scope_type' => 'client', 'scope_id' => $acme->id]);
    oldRule('check.warning', [$teamClient], ['scope_type' => 'client', 'scope_id' => $acme->id]);
    oldRule('site.recovered', [$teamClient], ['scope_type' => 'client', 'scope_id' => $acme->id]);
    // Team B: tag scope + a site rule muting inherited rules + a window + a changes rule + the raw cron key.
    $teamMixed = oldDestination('ops@example.test', ['quiet_start' => '20:00', 'quiet_end' => '06:00']);
    $teamOther = oldDestination('night@example.test');
    oldRule('site.down', [$teamMixed], ['scope_type' => 'tag', 'scope_id' => $tag, 'weekdays' => '[1,2,3,4,5]', 'window_start' => '08:00', 'window_end' => '18:00']);
    oldRule('check.warning', [$teamMixed]);
    oldRule('site.down', [$teamOther]);
    oldRule('site.down', [$teamOther], ['scope_type' => 'site', 'scope_id' => $betaSite->id, 'mute_inherited' => true]);
    oldRule('dns.records_changed', [$teamMixed], ['digest' => false]);
    oldRule('cron.task_result_changed', [$teamMixed]);

    runUnifyRecipients();

    $report = migrationReport();
    // Follow = all keys with no digest for the followed site; personal digest gates Warning and Error (NotificationTiming).
    $memberChannel = DB::table('notification_destinations')->where('id', $personal)->sole();
    expect($memberChannel->account_mail)->toBeTrue()->and($memberChannel->recipient_kind)->toBe('user');
    expect(gridOf($personal))->toBe(expectedGrid(['outage' => 'immediate', 'failure' => 'immediate', 'check_error' => 'digest', 'warning' => 'digest',
        'expiring' => 'digest', 'recovery' => 'immediate', 'changes' => 'immediate']));
    expect(DB::table('notification_site_mutes')->where('destination_id', $personal)->orderBy('site_id')->pluck('site_id')->all())
        ->toBe(array_values(array_diff($allSites, [$acmeSites[0]->id])));
    expect((array) DB::table('notification_schedules')->where('user_id', $member->id)->first(['quiet_start', 'quiet_end', 'quiet_weekends', 'digest_time', 'digest_weekdays']))
        ->toBe(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_weekends' => true, 'digest_time' => '09:30', 'digest_weekdays' => '[1, 3, 5]']);
    expect($report)->toContain(
        "Channel #{$personal} (MEMBER@example.test): dns.records_changed alerts were partly digest, now immediate as changes.",
        "Channel #{$personal} (MEMBER@example.test): language follows the account (de) instead of en.",
        "Channel #{$personal} (MEMBER@example.test): quiet hours and digest use the account time zone America/New_York instead of Europe/Berlin.",
        "Channel #{$personal} (MEMBER@example.test): ".(count($allSites) - 1)." sites muted to keep today's site selection; sites added later are not muted.",
    );
    // A non-account address stays a separate user channel; the rule digest on check.failing covers Check error only.
    $adminChannel = DB::table('notification_destinations')->where('id', $adminAddress)->sole();
    expect($adminChannel->account_mail)->toBeFalse()->and($adminChannel->config)->toBe('{"email": "alerts-admin@example.test"}');
    expect(gridOf($adminAddress))->toBe(expectedGrid(['failure' => 'immediate', 'check_error' => 'digest']));
    expect($report)->toContain("Channel #{$adminAddress} (alerts-admin@example.test): address differs from the account email of user #{$admin->id}; kept as a separate address.");
    // Users without an account channel get one, all off; System follows the old system mail eligibility.
    $adminAccount = DB::table('notification_destinations')->where('user_id', $admin->id)->where('account_mail', true)->sole();
    $quietAccount = DB::table('notification_destinations')->where('user_id', $quiet->id)->where('account_mail', true)->sole();
    expect(gridOf($adminAccount->id))->toBe(expectedGrid(['system' => 'immediate']))
        ->and(gridOf($quietAccount->id))->toBe(expectedGrid([]));
    expect($report)->toContain("User #{$quiet->id}: account email channel added with every alert category off, as before (System: off).");
    // One client scope; the type filter dropped recoveries; Warning and Expiring go to the digest overlay. A check.warning
    // rule also delivered domain-check warnings, which are Expiring now, so that cell turns on and the broadening is reported.
    expect(DB::table('notification_destinations')->where('id', $teamClient)->value('scope_client_id'))->toBe($acme->id);
    expect(gridOf($teamClient))->toBe(expectedGrid(['outage' => 'immediate', 'warning' => 'digest', 'expiring' => 'digest']));
    expect($report)->toContain("Channel #{$teamClient} (acme@example.test): expiring turned on although certificate.expiring had no rule; those alerts are now delivered too.");
    // Mixed site sets become their union; windows and the raw cron key are dropped and reported.
    expect(gridOf($teamMixed))->toBe(expectedGrid(['outage' => 'immediate', 'warning' => 'immediate', 'expiring' => 'immediate', 'changes' => 'immediate']));
    expect(DB::table('notification_site_mutes')->where('destination_id', $teamMixed)->count())->toBe(0);
    expect($report)->toContain(
        "Channel #{$teamMixed} (ops@example.test): alert types reached different sites; every type now reaches the union of ".count($allSites).' sites.',
        "Channel #{$teamMixed} (ops@example.test): changes turned on although application_health.component_changed, certificate_health.certificate_changed, broken_links.new_broken_found, mixed_content.new_finding, domain.expiry_changed, domain.registration_changed, lighthouse.score_threshold_crossed, sitemap.issues_changed, scheduled_task.missed had no rule; those alerts are now delivered too.",
    );
    expect(array_filter($report, fn (string $line): bool => str_contains($line, 'time window 1,2,3,4,5 08:00–18:00 dropped')))->toHaveCount(1);
    expect(array_filter($report, fn (string $line): bool => str_contains($line, '(cron.task_result_changed, all): unreachable key dropped')))->toHaveCount(1);
    // mute_inherited: the site rule keeps beta's own site, inherited all-sites rules no longer reach it.
    expect(gridOf($teamOther))->toBe(expectedGrid(['outage' => 'immediate']));
    expect(DB::table('notification_site_mutes')->where('destination_id', $teamOther)->count())->toBe(0);
    // Team schedule: the most common tuple wins (two quiet 20:00–06:00 against one without), reported.
    expect((array) DB::table('notification_schedules')->where('recipient_kind', 'team')->first(['quiet_start', 'quiet_end']))->toBe(['quiet_start' => '20:00', 'quiet_end' => '06:00']);
    expect($report)->toContain('Team schedule: team channels had 2 different quiet-hour/digest settings; the most common one now applies to all team channels.');
});

it('mutes the complement of a single-client scope on a personal channel and drops rules that reach no site', function (): void {
    $user = User::factory()->member()->create(['email' => 'scoped@example.test']);
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id]);
    Site::factory()->create();
    restoreRoutingSchema();
    $channel = oldDestination('scoped@example.test', ['user_id' => $user->id]);
    oldRule('site.down', [$channel], ['scope_type' => 'client', 'scope_id' => $client->id]);
    $orphan = oldRule('site.recovered', [$channel], ['scope_type' => 'site', 'scope_id' => 999999]);

    runUnifyRecipients();

    expect(gridOf($channel))->toBe(expectedGrid(['outage' => 'immediate']));
    expect(DB::table('notification_destinations')->where('id', $channel)->value('scope_client_id'))->toBeNull();
    expect(DB::table('notification_site_mutes')->where('destination_id', $channel)->pluck('site_id')->all())
        ->toBe(DB::table('sites')->where('id', '<>', $site->id)->orderBy('id')->pluck('id')->all());
    expect(migrationReport())->toContain("Rule #{$orphan} (site.recovered, site #999999): reaches no current site; not converted.");
});

it('keeps pending work resumable and makes deliveries restrict channel deletion', function (): void {
    $check = Check::factory()->create();
    restoreRoutingSchema();
    $team = oldDestination('team@example.test');
    oldRule('check.warning', [$team]);
    $event = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'type' => 'check.result_changed',
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $pending = DB::table('deliveries')->insertGetId(['event_id' => $event->id, 'destination_id' => $team, 'state' => 'pending', 'attempts' => 2,
        'next_attempt_at' => now()->addMinutes(5), 'suppression_evaluated_at' => now(), 'digest' => true, 'rule_digest' => true]);

    runUnifyRecipients();

    expect((array) DB::table('deliveries')->where('id', $pending)->first(['state', 'attempts', 'digest', 'suppressed', 'category', 'origin']))
        ->toBe(['state' => 'pending', 'attempts' => 2, 'digest' => true, 'suppressed' => false, 'category' => 'warning', 'origin' => 'system']);
    expect(fn () => DB::table('notification_destinations')->where('id', $team)->delete())->toThrow(QueryException::class);
});

it('keeps all-sites and client rules on an install without sites, so future sites are covered', function (): void {
    $client = Client::factory()->create();
    DB::table('sites')->delete();
    restoreRoutingSchema();
    $team = oldDestination('team@example.test');
    $clientTeam = oldDestination('client-team@example.test');
    oldRule('site.down', [$team]);
    oldRule('check.warning', [$team], ['digest' => true]);
    oldRule('site.down', [$clientTeam], ['scope_type' => 'client', 'scope_id' => $client->id]);

    runUnifyRecipients();

    expect(gridOf($team))->toBe(expectedGrid(['outage' => 'immediate', 'warning' => 'digest', 'expiring' => 'digest']));
    expect(gridOf($clientTeam))->toBe(expectedGrid(['outage' => 'immediate']));
    expect(DB::table('notification_destinations')->where('id', $team)->value('scope_client_id'))->toBeNull();
    expect(DB::table('notification_destinations')->where('id', $clientTeam)->value('scope_client_id'))->toBe($client->id);
    expect(DB::table('notification_site_mutes')->count())->toBe(0);
    expect(array_filter(migrationReport(), fn (string $line): bool => str_contains($line, 'not converted')))->toBe([]);
    // A site created after the upgrade is covered without any further configuration.
    $site = Site::factory()->create(['client_id' => $client->id]);
    $context = new RoutingContext($site, NotificationCategory::Outage,
        AlertSeverity::Down, true, null);
    $reached = array_map(fn (array $candidate): int => $candidate['channel']->id, array_filter(
        app(NotificationRecipients::class)->candidates($context), fn (array $candidate): bool => $candidate['excluded'] === null));
    expect(array_values($reached))->toBe([$team, $clientTeam]);
});

it('previews and converts with the settings store of an install from before later settings existed', function (): void {
    restoreRoutingSchema();
    $team = oldDestination('team@example.test', ['timezone' => 'America/New_York', 'quiet_start' => '22:00', 'quiet_end' => '06:00']);
    oldRule('site.down', [$team]);
    // The upgrade preflight runs the preview before any migration: settings added since the conversion are not stored yet.
    DB::table('settings')->where('group', 'instance')->where('name', 'mail_design')->delete();
    app()->forgetInstance(InstanceSettings::class);

    // The plan the preflight command prints (its read-only transaction cannot nest in the test transaction).
    expect((require base_path(unifyRecipientsPath()))->plan()['lines'])
        ->toContain('Channel #'.$team.' (team@example.test): quiet hours and digest use the instance time zone Europe/Berlin instead of America/New_York.');
    runUnifyRecipients();
    expect(gridOf($team))->toBe(expectedGrid(['outage' => 'immediate']));
});

it('reports every routing key whose timing changes, with its old and new timing', function (): void {
    Check::factory()->create();
    restoreRoutingSchema();
    $team = oldDestination('team@example.test');
    // Immediate warnings plus digested certificate expiry: Expiring has one cell, so expiry turns immediate.
    oldRule('check.warning', [$team]);
    oldRule('certificate.expiring', [$team], ['digest' => true]);
    // A changes rule with a digest overlay: warning-level changes used to wait; the grid keeps the digest.
    $digested = oldDestination('digest@example.test', ['digest_enabled' => true]);
    oldRule('sitemap.issues_changed', [$digested], ['digest' => true]);
    oldRule('check.warning', [$digested]);

    runUnifyRecipients();

    expect(gridOf($team))->toBe(expectedGrid(['warning' => 'immediate', 'expiring' => 'immediate']));
    expect(gridOf($digested))->toBe(expectedGrid(['warning' => 'digest', 'expiring' => 'digest', 'changes' => 'digest']));
    $report = migrationReport();
    expect($report)->toContain("Channel #{$team} (team@example.test): certificate.expiring alerts were digest, now immediate as expiring.");
    expect(array_filter($report, fn (string $line): bool => str_contains($line, "Channel #{$digested} (digest@example.test): ") && str_contains($line, 'alerts were')))->toBe([]);
    expect(array_filter($report, fn (string $line): bool => str_contains($line, 'changes stay immediate')))->toBe([]);
});

it('keeps a site excluded from a client rule muted on the client-scoped channel', function (): void {
    $client = Client::factory()->create();
    [$kept, $excluded] = Site::factory()->count(2)->create(['client_id' => $client->id])->all();
    Site::factory()->create();
    restoreRoutingSchema();
    $team = oldDestination('client-team@example.test');
    $other = oldDestination('other@example.test');
    oldRule('site.down', [$team], ['scope_type' => 'client', 'scope_id' => $client->id]);
    // A site rule for another destination replaces the inherited site.down rules on this site.
    oldRule('site.down', [$other], ['scope_type' => 'site', 'scope_id' => $excluded->id, 'mute_inherited' => true]);

    runUnifyRecipients();

    expect(DB::table('notification_destinations')->where('id', $team)->value('scope_client_id'))->toBe($client->id);
    expect(DB::table('notification_site_mutes')->where('destination_id', $team)->pluck('site_id')->all())->toBe([$excluded->id]);
    expect(migrationReport())->toContain("Channel #{$team} (client-team@example.test): 1 sites of client #{$client->id} muted to keep today's site selection; sites added later are not muted.");
    $route = fn (Site $site): array => array_values(array_map(fn (array $candidate): int => $candidate['channel']->id, array_filter(
        app(NotificationRecipients::class)->candidates(new RoutingContext($site,
            NotificationCategory::Outage, AlertSeverity::Down, true, null)),
        fn (array $candidate): bool => $candidate['excluded'] === null)));
    expect($route($kept))->toBe([$team])->and($route($excluded))->toBe([$other]);
});

it('reports a timing change for the sites whose rule changed timing, not only for the earliest rule', function (): void {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    restoreRoutingSchema();
    $team = oldDestination('team@example.test');
    // Immediate warnings on site A, digested warnings on site B: the single Warning cell becomes immediate.
    oldRule('check.warning', [$team], ['scope_type' => 'site', 'scope_id' => $a->id]);
    oldRule('check.warning', [$team], ['scope_type' => 'site', 'scope_id' => $b->id, 'digest' => true]);

    runUnifyRecipients();

    expect(gridOf($team)['warning'])->toBe('immediate');
    expect(migrationReport())->toContain("Channel #{$team} (team@example.test): check.warning alerts were digest on 1 of 2 sites, now immediate as warning.");
    expect(array_filter(migrationReport(), fn (string $line): bool => str_contains($line, 'alerts were immediate')))->toBe([]);
});

it('requires the pre-upgrade backup for rollback', function (): void {
    $migration = require base_path(unifyRecipientsPath());

    expect(fn () => $migration->down())->toThrow(LogicException::class,
        'This migration converts notification rules, follows and noise settings into channels. Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
});
