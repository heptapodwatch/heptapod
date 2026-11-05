<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

/*
 * Request-path engine transactions run once more when Postgres aborts them as a deadlock victim
 * (NotificationLocks::transaction). A sequence-counted trigger raises 40P01 the first $deadlocks times a row is written, as
 * Postgres aborts a victim. Laravel retries only an outermost transaction, so each case commits its fixture and runs the
 * real route outside the test's wrapping transaction, with a real transaction manager (its rollback callbacks fire).
 * Not a Gate test: committed data (scripts/check-gate-tests.php).
 */

/** Raises 40P01 the first $deadlocks times $table gets a row write matching $condition (a plpgsql expression on NEW/OLD). */
function requestDeadlockTrigger(string $table, string $timing, string $condition, int $deadlocks): void
{
    // A sequence counts the aborted attempts: it does not roll back with them.
    DB::unprepared('CREATE SEQUENCE nl_request_deadlocks');
    DB::unprepared("CREATE FUNCTION nl_request_deadlock() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF {$condition} AND (SELECT CASE WHEN is_called THEN last_value ELSE 0 END FROM nl_request_deadlocks) < {$deadlocks} THEN
            PERFORM nextval('nl_request_deadlocks');
            RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END IF;
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END \$\$");
    DB::unprepared("CREATE TRIGGER nl_request_deadlock {$timing} ON {$table} FOR EACH ROW EXECUTE FUNCTION nl_request_deadlock()");
}

function requestDeadlockCount(): int
{
    return (int) DB::scalar('SELECT CASE WHEN is_called THEN last_value ELSE 0 END FROM nl_request_deadlocks');
}

/** Leaves the test's wrapping transaction so the request's transaction is the top level; returns the cleanup. */
function requestDeadlockCommitted(Closure $cleanup): Closure
{
    DB::commit();
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);

    return static function () use ($cleanup): void {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::unprepared('DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = \'nl_request_deadlock\') THEN
                EXECUTE (SELECT format(\'DROP TRIGGER nl_request_deadlock ON %s\', tgrelid::regclass) FROM pg_trigger WHERE tgname = \'nl_request_deadlock\');
            END IF; END $$');
        DB::unprepared('DROP FUNCTION IF EXISTS nl_request_deadlock(); DROP SEQUENCE IF EXISTS nl_request_deadlocks');
        $cleanup();
        DB::connection()->setTransactionManager(app('db.transactions'));
        DB::beginTransaction();
    };
}

/**
 * A committed Owner and a site with one uptime check, an open outage and a queued alert on a team channel.
 *
 * @return array{owner: User, site: Site, check: Check, incident: Incident, delivery: Delivery, cleanup: Closure(): void}
 */
function requestDeadlockSite(): array
{
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://retry-'.$client->id.'.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(10)]);
    $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'occurred_at' => now()->subMinutes(10), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
        'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
    $channel = NotificationDestination::factory()->create();
    $delivery = Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'next_attempt_at' => now()->addHour(),
        'suppressed' => false, 'suppression_evaluated_at' => now()]);

    return ['owner' => $owner, 'site' => $site, 'check' => $check, 'incident' => $incident, 'delivery' => $delivery,
        'cleanup' => static function () use ($owner, $client, $site, $channel, $roleIds): void {
            $events = DB::table('events')->where('site_id', $site->id)->orWhere('client_id', $client->id)->pluck('id');
            DB::table('deliveries')->whereIn('event_id', $events)->delete();
            DB::table('events')->whereIn('id', $events)->delete();
            DB::table('activity_log')->where('causer_id', $owner->id)->orWhere('subject_id', $channel->id)->delete();
            DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
            DB::table('notification_destinations')->where('id', $channel->id)->delete();
            DB::table('maintenance_periods')->where('site_id', $site->id)->delete();
            DB::table('unmonitored_periods')->where('site_id', $site->id)->delete();
            DB::table('check_run_attempts')->whereIn('check_id', DB::table('checks')->where('site_id', $site->id)->select('id'))->delete();
            DB::table('incidents')->where('site_id', $site->id)->delete();
            DB::table('checks')->where('site_id', $site->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('api_idempotency_records')->where('user_id', $owner->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_id', $owner->id)->delete();
            DB::table('users')->where('id', $owner->id)->delete();
            Role::query()->whereNotIn('id', $roleIds)->delete();
        }];
}

it('deletes a site whose first attempt deadlocks exactly once on the retry, and rolls back a second deadlock completely', function (int $deadlocks): void {
    Queue::fake();
    $setup = requestDeadlockSite();
    // The cascade into the outage is the deletion's last write, after the resolution event, the suppression and the redaction.
    requestDeadlockTrigger('incidents', 'BEFORE DELETE', 'OLD.site_id = '.$setup['site']->id, $deadlocks);
    $cleanup = requestDeadlockCommitted($setup['cleanup']);
    try {
        $response = $this->actingAs($setup['owner'])->delete('/sites/'.$setup['site']->id, ['name' => $setup['site']->displayName()]);
        if ($deadlocks === 1) {
            $response->assertRedirect(route('sites.index'));
            expect(requestDeadlockCount())->toBe(1)
                ->and(Site::query()->find($setup['site']->id))->toBeNull()
                ->and(Event::query()->where('type', 'incident.resolved')->where('incident_id', null)->where('client_id', $setup['site']->client_id)->count())->toBe(1)
                ->and(DB::table('activity_log')->where('event', 'incident.resolved')->where('subject_id', $setup['incident']->id)->count())->toBe(1)
                ->and($setup['delivery']->refresh()->suppression_reason)->toBe('monitoring_stopped');
        } else {
            $response->assertServerError();
            expect(requestDeadlockCount())->toBe(2)
                ->and(Site::query()->find($setup['site']->id))->not->toBeNull()
                ->and(Incident::query()->whereKey($setup['incident']->id)->whereNull('resolved_at')->exists())->toBeTrue()
                ->and(Event::query()->where('type', 'incident.resolved')->where('site_id', $setup['site']->id)->exists())->toBeFalse()
                ->and(DB::table('activity_log')->where('event', 'incident.resolved')->where('subject_id', $setup['incident']->id)->exists())->toBeFalse()
                ->and($setup['delivery']->refresh()->suppressed)->toBeFalse();
        }
    } finally {
        $cleanup();
    }
})->with(['one deadlock' => [1], 'two deadlocks' => [2]]);

it('creates an API maintenance window whose first attempt deadlocks exactly once and stores its idempotency record once', function (): void {
    app()->singleton(ApiSpec::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $setup = requestDeadlockSite();
    $token = app(CreateApiToken::class)($setup['owner'], 'maintenance', ['maintenance:read', 'maintenance:write']);
    requestDeadlockTrigger('maintenance_periods', 'BEFORE INSERT', 'NEW.site_id = '.$setup['site']->id, 1);
    $cleanup = requestDeadlockCommitted($setup['cleanup']);
    try {
        $body = ['monitor_id' => $setup['site']->id, 'starts_at' => '2026-10-05 10:00', 'ends_at' => '2026-10-05 11:00', 'name' => 'Migration'];
        $id = $this->withToken($token)->withHeader('Idempotency-Key', 'retry-once')->postJson('/api/maintenance-periods', $body)
            ->assertCreated()->json('id');
        expect(requestDeadlockCount())->toBe(1)
            ->and(MaintenancePeriod::query()->where('site_id', $setup['site']->id)->pluck('id')->all())->toBe([$id])
            ->and(DB::table('api_idempotency_records')->where('user_id', $setup['owner']->id)->where('response_status', 201)->count())->toBe(1);
        // The stored response replays; no second window.
        $this->withToken($token)->withHeader('Idempotency-Key', 'retry-once')->postJson('/api/maintenance-periods', $body)
            ->assertCreated()->assertJsonPath('id', $id);
        expect(MaintenancePeriod::query()->where('site_id', $setup['site']->id)->count())->toBe(1);
    } finally {
        $cleanup();
    }
});

it('counts a manual API run once against its budgets when its first attempt deadlocks, and not at all when it rolls back', function (string $outcome): void {
    Bus::fake();
    $setup = requestDeadlockSite();
    DB::table('checks')->where('id', $setup['check']->id)->update(['enabled' => true, 'claim_token' => null, 'lease_expires_at' => null]);
    $token = $setup['owner']->createToken('runs', ['checks:run']);
    $keys = ['manual-runs:user:'.$setup['owner']->id, 'manual-runs:token:'.$token->accessToken->id];
    // The first attempt deadlocks when it stores its response, after both budget hits. A failed response record is a
    // rollback of the whole request.
    requestDeadlockTrigger('api_idempotency_records', 'BEFORE UPDATE', 'NEW.response_status IS NOT NULL', $outcome === 'retried' ? 1 : 2);
    $cleanup = requestDeadlockCommitted($setup['cleanup']);
    try {
        RateLimiter::hit($keys[0], 60);
        // ApiTestCase validates against the pinned Oh Dear spec, which has no Heptapod run endpoint: call the kernel directly.
        $response = app(HttpKernel::class)->handle(Request::create('/api/checks/'.$setup['check']->id.'/runs', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken, 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'run-'.str_replace(' ', '-', $outcome)]));
        if ($outcome === 'retried') {
            expect($response->getStatusCode())->toBe(202, (string) $response->getContent());
            expect(requestDeadlockCount())->toBe(1)
                ->and([RateLimiter::attempts($keys[0]), RateLimiter::attempts($keys[1])])->toBe([2, 1])
                ->and(CheckRunAttempt::query()->where('check_id', $setup['check']->id)->count())->toBe(1);
        } else {
            expect($response->getStatusCode())->toBe(500, (string) $response->getContent())
                ->and(requestDeadlockCount())->toBe(2)
                ->and([RateLimiter::attempts($keys[0]), RateLimiter::attempts($keys[1])])->toBe([1, 0])
                ->and(CheckRunAttempt::query()->where('check_id', $setup['check']->id)->exists())->toBeFalse();
        }
    } finally {
        RateLimiter::clear($keys[0]);
        RateLimiter::clear($keys[1]);
        $cleanup();
    }
})->with(['one deadlock' => ['retried'], 'two deadlocks' => ['rolled back']]);

it('refuses a manual API run over its token budget with 429 and leaves both budgets unchanged', function (): void {
    Bus::fake();
    $setup = requestDeadlockSite();
    DB::table('checks')->where('id', $setup['check']->id)->update(['enabled' => true, 'claim_token' => null, 'lease_expires_at' => null]);
    $token = $setup['owner']->createToken('runs', ['checks:run']);
    $keys = ['manual-runs:user:'.$setup['owner']->id, 'manual-runs:token:'.$token->accessToken->id];
    $cleanup = requestDeadlockCommitted($setup['cleanup']);
    try {
        foreach (range(1, 5) as $hit) {
            RateLimiter::hit($keys[1], 60);
        }
        $response = app(HttpKernel::class)->handle(Request::create('/api/checks/'.$setup['check']->id.'/runs', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken, 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'over-budget']));
        expect($response->getStatusCode())->toBe(429, (string) $response->getContent())
            ->and(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['code'])->toBe('manual_run_budget')
            ->and([RateLimiter::attempts($keys[0]), RateLimiter::attempts($keys[1])])->toBe([0, 5])
            ->and(CheckRunAttempt::query()->where('check_id', $setup['check']->id)->exists())->toBeFalse()
            ->and(DB::table('api_idempotency_records')->where('user_id', $setup['owner']->id)->exists())->toBeFalse();
    } finally {
        RateLimiter::clear($keys[0]);
        RateLimiter::clear($keys[1]);
        $cleanup();
    }
});
