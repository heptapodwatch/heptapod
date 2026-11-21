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
        'token' => app(CreateApiToken::class)($owner, 'scoped-to-source', ['*'], $from->id)];
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
 * Commits the fixtures, then sends $method $uri (with a bearer token or as a signed-in web user) while $change is open.
 * With $pauseAfter the request waits after its first query containing that SQL fragment until $change commits. `waited`
 * says whether the request was blocked by this connection (the change) before it committed; a request that neither waits
 * on the change nor answers in time fails the test instead of committing.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, body: mixed, waited: bool, blocked_on: string|null} blocked_on: the statement the request waited in
 */
function siteWriteRace(Closure $change, string $method, string $uri, array $body = [], string $token = '', ?User $webUser = null, string $pauseAfter = ''): array
{
    DB::commit();
    $request = new Process([PHP_BINARY, __DIR__.'/site-write-request.php', $method, $uri, json_encode($body === [] ? new stdClass : $body, JSON_THROW_ON_ERROR),
        $token, $webUser instanceof User ? (string) $webUser->id : '', $pauseAfter], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null',
        ], timeout: 30);
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
        $request->wait();
        expect($request->isSuccessful())->toBeTrue($request->getErrorOutput())
            ->and(json_validate($request->getOutput()))->toBeTrue($request->getOutput());

        /** @var array{status: int, body: mixed} $response */
        $response = json_decode($request->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return [...$response, 'waited' => $waited, 'blocked_on' => $blocked];
    } finally {
        $request->stop(1);
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
}
