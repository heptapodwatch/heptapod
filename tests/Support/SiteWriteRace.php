<?php

declare(strict_types=1);

use Heptapod\Actions\CreateApiToken;
use Heptapod\Actions\MoveSiteToClient;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Writes race a change that was not yet committed when they authorized. The change runs in this connection and holds its
 * transaction open while a second process sends the request; the change commits once the request waits on one of its
 * locks (or has already answered). Not a Gate test: a subprocess and committed data (scripts/check-gate-tests.php). The
 * commit makes the next test start from a freshly migrated database (Tests\TestCase).
 */

/** The advisory key a paused request (site-write-request.php) waits for; a change that pauses it takes the key first. */
const SiteWriteRacePause = 7_314_001;

/** @return array{owner: User, site: Site, from: Client, to: Client, token: string} the site belongs to $from; the token is scoped to it */
function siteWriteRaceFixture(): array
{
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $from = Client::factory()->create();
    $to = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $from->id]);

    return ['owner' => $owner, 'site' => $site, 'from' => $from, 'to' => $to,
        'token' => app(CreateApiToken::class)($owner, 'scoped-to-source', CreateApiToken::Abilities, $from->id)];
}

/**
 * The move from $fixture['from'] to $fixture['to'], as the change a write races.
 *
 * @param  array{owner: User, site: Site, from: Client, to: Client, token: string}  $fixture
 */
function siteWriteRaceMove(array $fixture): Closure
{
    return static function () use ($fixture): void {
        DB::select('SELECT pg_advisory_xact_lock(?)', [SiteWriteRacePause]);
        app(MoveSiteToClient::class)($fixture['owner'], $fixture['site'], $fixture['to']->id);
    };
}

/**
 * The request as its own process (site-write-request.php), not started. With $pauseAfter it waits after its first query
 * containing that SQL fragment for SiteWriteRacePause.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function siteWriteRaceRequest(string $method, string $uri, array $body = [], string $token = '', ?User $webUser = null, string $pauseAfter = '', array $headers = [], string $followUp = ''): Process
{
    return new Process([PHP_BINARY, __DIR__.'/site-write-request.php', $method, $uri, json_encode($body === [] ? new stdClass : $body, JSON_THROW_ON_ERROR),
        $token, $webUser instanceof User ? (string) $webUser->id : '', $pauseAfter, json_encode($headers === [] ? new stdClass : $headers, JSON_THROW_ON_ERROR), $followUp],
        base_path(), siteWriteRaceEnvironment(), timeout: 30);
}

/** @return array<string, string> the environment of a process booted against this test database */
function siteWriteRaceEnvironment(): array
{
    return ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null'];
}

/**
 * Waits until the session named $application is blocked by the backend $blocker (by any session when null) and returns the
 * statement it waits in; fails the test when the process ends first or after 20 s.
 */
function siteWriteRaceBlocked(Process $process, string $application, ?int $blocker = null): string
{
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $waiting = DB::table('pg_stat_activity')->where('application_name', $application);
        $waiting = $blocker === null ? $waiting->whereRaw('cardinality(pg_blocking_pids(pid)) > 0') : $waiting->whereRaw('? = ANY(pg_blocking_pids(pid))', [$blocker]);
        $statement = $waiting->value('query');
        if (is_string($statement)) {
            return $statement;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline && $process->isRunning());
    expect(false)->toBeTrue($application.' never waited: '.$process->getOutput().$process->getErrorOutput());

    return '';
}

/** @return array{status: int, headers: array<string, string>, body: mixed, follow_up_status?: int} the answer of a finished site-write-request.php process */
function siteWriteRaceResponse(Process $request): array
{
    $request->wait();
    expect($request->isSuccessful())->toBeTrue($request->getErrorOutput())
        ->and(json_validate($request->getOutput()))->toBeTrue($request->getOutput());

    /** @var array{status: int, headers: array<string, string>, body: mixed, follow_up_status?: int} */
    return json_decode($request->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Commits the fixtures, then sends $method $uri (with a bearer token or as a signed-in web user) while $change is open.
 * With $pauseAfter the request waits after its first query containing that SQL fragment until $change commits. `waited`
 * says whether the request was blocked by this connection (the change) before it committed; a request that neither waits
 * on the change nor answers in time fails the test instead of committing.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 * @return array{status: int, headers: array<string, string>, body: mixed, follow_up_status?: int, waited: bool, blocked_on: string|null} blocked_on: the statement the request waited in
 */
function siteWriteRace(Closure $change, string $method, string $uri, array $body = [], string $token = '', ?User $webUser = null, string $pauseAfter = '', array $headers = [], string $followUp = ''): array
{
    DB::commit();
    $request = siteWriteRaceRequest($method, $uri, $body, $token, $webUser, $pauseAfter, $headers, $followUp);
    try {
        DB::beginTransaction();
        $change();
        $parent = (int) DB::scalar('SELECT pg_backend_pid()');
        $request->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $blocked = DB::table('pg_stat_activity')->where('application_name', 'site_write_race')
                ->whereRaw('? = ANY(pg_blocking_pids(pid))', [$parent])->value('query');
            $waited = $blocked !== null;
            if ($waited || ! $request->isRunning()) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        expect($waited || ! $request->isRunning())->toBeTrue('The request neither waited on the change nor answered: '.$request->getErrorOutput());
        DB::commit();

        return [...siteWriteRaceResponse($request), 'waited' => $waited, 'blocked_on' => $blocked];
    } finally {
        $request->stop(1);
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
}
