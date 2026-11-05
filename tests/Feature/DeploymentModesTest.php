<?php

declare(strict_types=1);

use Illuminate\Support\Env as Environment;
use Illuminate\Support\Facades\URL;

it('trusts forwarded origins only from configured proxies', function (array $proxies, string $remote, string $origin): void {
    Environment::getRepository()->set('TRUSTED_PROXIES', implode(', ', $proxies));
    config(['trustedproxy' => require config_path('trustedproxy.php')]);

    $this->withServerVariables(['REMOTE_ADDR' => $remote])
        ->withHeaders([
            'Host' => 'internal.example',
            'X-Forwarded-Host' => 'monitor.example.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])
        ->get('http://internal.example/dashboard')
        ->assertRedirect($origin.'/login');

    expect(URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => 1, 'hash' => 'probe']))
        ->toStartWith($origin.'/email/verify/1/probe?');
})->with([
    'configured proxy' => [['10.20.0.0/24'], '10.20.0.2', 'https://monitor.example.com'],
    'untrusted peer' => [['10.20.0.0/24'], '10.30.0.2', 'http://internal.example'],
    'no proxy default' => [[], '10.20.0.2', 'http://internal.example'],
]);
