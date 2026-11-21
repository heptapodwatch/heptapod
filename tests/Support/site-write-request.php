<?php

declare(strict_types=1);

/*
 * One request through the HTTP kernel in its own process and database session (application_name site_write_race), for
 * SiteWriteRaceTest. Arguments: method, URI, JSON body, bearer token ('' for none), web user id ('' for none), and an SQL
 * fragment ('' for none): after the first query containing it the request waits for SiteWriteRacePause, which the racing
 * change holds, so a read made of several queries sees the change between them. Prints the status and the JSON body.
 */

use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/SiteWriteRace.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
DB::statement("SET application_name = 'site_write_race'");
[, $method, $uri, $body, $bearer, $webUser, $pauseAfter] = $argv;
if ($pauseAfter !== '') {
    $paused = false;
    DB::listen(static function (QueryExecuted $query) use (&$paused, $pauseAfter): void {
        if (! $paused && str_contains($query->sql, $pauseAfter)) {
            $paused = true;
            DB::select('SELECT pg_advisory_lock(?)', [SiteWriteRacePause]);
            DB::select('SELECT pg_advisory_unlock(?)', [SiteWriteRacePause]);
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
if ($bearer !== '') {
    $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
}
if ($webUser !== '') {
    Auth::guard('web')->setUser(User::query()->findOrFail((int) $webUser));
}
$request = Request::create($uri, $method, server: $server, content: $body);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
