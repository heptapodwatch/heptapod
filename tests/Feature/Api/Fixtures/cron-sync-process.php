<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
DB::statement("SET application_name = 'cron_sync_progress_test'");
$request = Request::create('/api/monitors/'.$argv[1].'/cron-checks/sync', 'POST', server: [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$argv[2],
], content: $argv[3]);
$response = $kernel->handle($request);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException($response->getContent());
}
$kernel->terminate($request, $response);
echo "synced\n";
