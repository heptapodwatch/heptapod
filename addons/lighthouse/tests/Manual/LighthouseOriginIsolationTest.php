<?php

declare(strict_types=1);

use Heptapod\Contracts\RunResult;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Pest.php';

it('passes native CPU and desktop settings without sending credentials to redirected or third-party origins', function (): void {
    $server = new Process(['node', __DIR__.'/../Support/origin-server.mjs']);
    $server->setTimeout(150);
    $server->start();
    try {
        expect($server->waitUntil(fn (string $type, string $output): bool => preg_match('/^\d+\n$/', $output) === 1))->toBeTrue();
        $port = (int) strtok($server->getOutput(), "\n");
        $check = lighthouseCheck(['device_emulation' => 'desktop', 'cpu_slowdown_modifier' => 2.5,
            'http_client_headers' => [['name' => 'X-Lighthouse-Probe', 'value' => 'origin-only-probe']]]);
        $check->site->update(['url' => 'http://heptapod-app:'.$port.'/redirect']);

        $run = runLighthouse($check);

        expect($run->result)->toBe(RunResult::Succeeded, $run->error ?? '');
        expect($server->getOutput())->toContain('origin:expected', 'same-origin-asset:expected', 'redirect:absent', 'other-port-asset:absent');
        expect($server->getOutput())->not->toContain('origin-only-probe', 'unexpected');
        expect($server->getOutput())->not->toContain('private-connect');
        $json = gzdecode(base64_decode(DB::table('lighthouse_reports')->value('compressed_report'), true));
        expect($json)->not->toContain('origin-only-probe', 'X-Lighthouse-Probe');
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($server->getOutput())->toContain('script-ran:absent')->not->toContain('rtc-enabled');
        expect($report['configSettings']['throttling']['cpuSlowdownMultiplier'])->toBe(2.5)
            ->and($report['configSettings']['formFactor'])->toBe('desktop');
        expect($run->metrics)->toHaveKeys(['performance_score', 'accessibility_score', 'best_practices_score', 'seo_score']);
    } finally {
        $server->stop();
        expect($server->isRunning())->toBeFalse();
    }
});
