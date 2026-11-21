<?php

declare(strict_types=1);

/*
 * One request through the HTTP kernel in its own process and database session (application_name site_write_race), for
 * SiteWriteRaceTest. Arguments: method, URI, JSON body, bearer token ('' for none), web user id ('' for none). Prints the
 * status and the JSON body.
 */

use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
DB::statement("SET application_name = 'site_write_race'");
[, $method, $uri, $body, $bearer, $webUser] = $argv;
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
