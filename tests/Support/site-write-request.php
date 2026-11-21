<?php

declare(strict_types=1);

/*
 * One request through the HTTP kernel in its own process and database session (application_name site_write_race), for
 * SiteWriteRaceTest. Arguments: method, URI, JSON body, bearer token ('' for none), web user id ('' for none), and an SQL
 * fragment ('' for none): after the first query containing it the request waits for SiteWriteRacePause, which the racing
 * change holds, so a read made of several queries sees the change between them, and extra request headers as JSON
 * ('{}' for none), and a URI the same browser session requests next ('' for none). Prints the status, the headers and
 * the body (decoded JSON, or the raw text of an HTML page), and the follow-up status.
 */

use Heptapod\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
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
[, $method, $uri, $body, $bearer, $webUser, $pauseAfter, $headers, $followUp] = $argv;
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
foreach (json_decode($headers, true, flags: JSON_THROW_ON_ERROR) as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$cookies = [];
if ($webUser !== '') {
    // A real signed-in session in this process's session store: the login key and the password hash bound at sign-in
    // (RequireBoundSession), sent back as the encrypted session cookie like a browser does.
    $user = User::query()->findOrFail((int) $webUser);
    $app->instance('request', Request::create('/'));
    $guard = Auth::guard('web');
    if (! $guard instanceof SessionGuard) {
        throw new LogicException('The web guard is a session guard.');
    }
    $session = $app['session']->driver();
    $session->start();
    $session->put($guard->getName(), $user->getAuthIdentifier());
    $session->put('password_hash_web', $guard->hashPasswordForCookie((string) $user->getAuthPassword()));
    $session->save();
    $name = (string) config('session.cookie');
    $cookies[$name] = $app['encrypter']->encrypt(CookieValuePrefix::create($name, $app['encrypter']->getKey()).$session->getId(), false);
}
$request = Request::create($uri, $method, cookies: $cookies, server: $server, content: $body);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$content = (string) $response->getContent();
$result = ['status' => $response->getStatusCode(), 'headers' => array_map(static fn (array $values): string => implode(', ', $values), $response->headers->all()),
    'body' => json_validate($content) ? json_decode($content, true) : $content];
if ($followUp !== '') {
    // The next request of the same browser session: the same session cookie, nothing else carried over.
    Auth::forgetGuards();
    $next = Request::create($followUp, 'GET', cookies: $cookies, server: $server);
    $nextResponse = $kernel->handle($next);
    $kernel->terminate($next, $nextResponse);
    $result['follow_up_status'] = $nextResponse->getStatusCode();
}
echo json_encode($result, JSON_THROW_ON_ERROR);
