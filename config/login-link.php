<?php

declare(strict_types=1);

$additionalHosts = array_filter(array_map(trim(...), explode(',', (string) env('LOGIN_LINK_ALLOWED_HOSTS', ''))), fn (string $host): bool => $host !== '');
foreach ($additionalHosts as $host) {
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        throw new InvalidArgumentException('LOGIN_LINK_ALLOWED_HOSTS must contain exact hostnames without schemes, ports or wildcards.');
    }
}

return [
    'allowed_environments' => ['local'],
    'allowed_hosts' => ['localhost', '127.0.0.1', 'heptapod-app', ...$additionalHosts],
    'redirect_route_name' => 'sites.index',
];
