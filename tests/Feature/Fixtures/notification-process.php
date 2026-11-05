<?php

declare(strict_types=1);

use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Jobs\DispatchNotifications;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'sync', 'notifications.renotify_intervals_seconds' => null]);

$mode = $argv[1];
if ($mode === 'claim-kill') {
    $claim = app(ClaimNotificationDelivery::class)((int) $argv[2]);
    if ($claim === null) {
        throw new LogicException('Expected a claim before process death.');
    }
    posix_kill(getmypid(), SIGKILL);
} else {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => function () use ($argv, $mode) {
        file_put_contents($argv[2], "sent\n", FILE_APPEND | LOCK_EX);
        if ($mode === 'dispatch-hold') {
            file_put_contents($argv[3], 'sending');
            while (! file_exists($argv[4])) {
                usleep(10000);
            }
        }

        return Http::response([], 200);
    }]);
    app()->call(new DispatchNotifications()->handle(...));
}
