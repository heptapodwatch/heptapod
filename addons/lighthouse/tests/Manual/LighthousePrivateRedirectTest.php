<?php

declare(strict_types=1);

use Heptapod\Contracts\RunResult;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Pest.php';

it('denies a redirect away from the trusted site hostname to a reachable private address before connecting', function (): void {
    $server = new Process(['node', __DIR__.'/../Support/origin-server.mjs']);
    $server->setTimeout(150);
    $server->start();
    try {
        expect($server->waitUntil(fn (string $type, string $output): bool => preg_match('/^\d+\n$/', $output) === 1))->toBeTrue();
        $port = (int) strtok($server->getOutput(), "\n");
        $check = lighthouseCheck();
        $check->site->update(['url' => 'http://heptapod-app:'.$port.'/private-redirect']);
        $run = runLighthouse($check);
        expect($server->getOutput())->toContain('origin:absent')->not->toContain('private-connect');
        expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    } finally {
        $server->stop();
        expect($server->isRunning())->toBeFalse();
    }
});
