<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\RunResult;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$results = array_column(RunResult::cases(), 'value');
$core = [
    'incident.opened' => [RunResult::Failed->value, RunResult::Errored->value],
    'incident.cause_changed' => [RunResult::Failed->value, RunResult::Errored->value],
    'incident.resolved' => [RunResult::Succeeded->value, RunResult::Warning->value],
    // The recorder emits result changes independently of incident transitions.
    'check.result_changed' => $results,
];
$failures = 0;
$pairs = 0;
$registry = $app->make(CheckRegistry::class);
foreach ($registry->keys() as $key) {
    $addon = $registry->get($key);
    if (! $addon instanceof MailEvidenceProvider) {
        continue;
    }
    $required = $core;
    foreach ($addon->definition()->emittedEvents as $event) {
        // AddonEvent has no narrower result contract: every final result must be declared.
        $required[$event] = $results;
    }
    if ($key === CheckKey::Uptime) {
        $required['incident.still_down'] = [RunResult::Failed->value];
    }
    $tones = $addon->mailEventTones();
    foreach ($required as $event => $eventResults) {
        foreach ($eventResults as $result) {
            $pairs++;
            if (! ($tones[$event][$result] ?? null) instanceof MailTone) {
                fwrite(STDERR, 'Missing mail tone: addon='.$key->value.' event='.$event.' result='.$result.PHP_EOL);
                $failures++;
            }
        }
    }
}
echo 'Mail tones: '.$pairs.' event/result pairs checked; '.$failures.' missing.'.PHP_EOL;
exit($failures === 0 ? 0 : 1);
