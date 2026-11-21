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
 * With $pauseAfter the request waits after its first query containing that SQL fragment until $change commits.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, body: mixed}
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
        $request->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('application_name', 'site_write_race')->where('wait_event_type', 'Lock')->exists();
            if ($waiting || ! $request->isRunning()) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        DB::commit();
        $request->wait();
        expect($request->isSuccessful())->toBeTrue($request->getErrorOutput())
            ->and(json_validate($request->getOutput()))->toBeTrue($request->getOutput());

        /** @var array{status: int, body: mixed} */
        return json_decode($request->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        $request->stop(1);
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
}
