<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
try {
    $app->make(Kernel::class)->bootstrap();

    // A session lock spans migrations that commit their own transactions.
    // Failed processes release the lock on disconnect; every app replica uses the same key.
    DB::statement("SET lock_timeout = '120s'");
    DB::select('SELECT pg_advisory_lock(7646001)');
    try {
        $status = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        echo Artisan::output();
    } finally {
        DB::select('SELECT pg_advisory_unlock(7646001)');
    }
} catch (Throwable $exception) {
    // Laravel's standalone-script exception handler can render an error with exit code 0.
    // Startup must fail, not launch the server against an unmigrated schema.
    fwrite(STDERR, (string) $exception.PHP_EOL);
    exit(1);
}

exit($status);
