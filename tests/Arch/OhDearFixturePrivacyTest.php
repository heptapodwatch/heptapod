<?php

use Symfony\Component\Yaml\Yaml;

/** @return list<string> */
function ohDearFixtureHosts(string $value): array
{
    preg_match_all('/(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}|(?:[0-9]{1,3}\.){3}[0-9]{1,3}/i', $value, $matches);
    $hosts = $matches[0];
    preg_match_all('/(?<![a-z0-9:])(?:[a-f0-9]{0,4}:){2,7}[a-f0-9]{0,4}(?![a-z0-9:])/i', $value, $addresses);
    foreach ($addresses[0] as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $hosts[] = $address;
        }
    }
    $urlHost = preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $value) ? parse_url($value, PHP_URL_HOST) : null;
    if (is_string($urlHost)) {
        $hosts[] = trim($urlHost, '[]');
    }

    return array_values(array_unique(array_filter(array_map(strtolower(...), $hosts), static fn (string $host): bool => ! in_array(pathinfo($host, PATHINFO_EXTENSION), ['xml', 'json', 'html', 'php', 'jpg', 'png', 'pdf'], true))));
}

/** @return array<string, list<array{value: string, credential: bool}>> */
function ohDearFixtureStrings(): array
{
    $fixtures = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Fixtures')) as $file) {
        if (! in_array($file->getExtension(), ['json', 'yml'], true) || ! str_contains(strtolower($file->getPathname()), '/ohdear/')) {
            continue;
        }
        $data = $file->getExtension() === 'json' ? json_decode(file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR) : Yaml::parseFile($file->getPathname());
        $fixtures[$file->getPathname()] = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($data));
        foreach ($iterator as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $parent = $iterator->getSubIterator()->getArrayCopy();
            $credential = preg_match('/^(?:secret|token|password|api[_-]?key|authorization)$/i', (string) $key) === 1 || ($key === 'value' && strcasecmp($parent['name'] ?? '', 'Authorization') === 0);
            $fixtures[$file->getPathname()][] = ['value' => $value, 'credential' => $credential];
        }
    }

    return $fixtures;
}

arch('Oh Dear response fixtures contain only reserved or vendor hostnames', function (): void {
    foreach (ohDearFixtureStrings() as $file => $values) {
        // The pinned vendor reference contains public documentation URLs, not account responses.
        if (str_ends_with($file, '/ohdear-openapi.yml')) {
            continue;
        }
        $violations = 0;
        foreach ($values as $leaf) {
            foreach (ohDearFixtureHosts($leaf['value']) as $host) {
                $documentationIp = filter_var($host, FILTER_VALIDATE_IP) !== false && (str_starts_with($host, '192.0.2.') || str_starts_with($host, '198.51.100.') || str_starts_with($host, '203.0.113.') || str_starts_with(inet_pton($host), hex2bin('20010db8')));
                if (! $documentationIp && ! in_array($host, ['1.1.1.1', '8.8.8.8', 'example.com', 'ohdear.app'], true) && ! str_ends_with($host, '.example') && ! str_ends_with($host, '.example.com') && ! str_ends_with($host, '.ohdear.app')) {
                    $violations++;
                }
            }
        }
        expect($violations)->toBe(0, basename($file).': unapproved hostname count');
    }
});

arch('all Oh Dear fixtures contain no captured account hostnames or avatar identifiers', function (): void {
    // Synthetic captured identifiers keep this privacy contract runnable without private account captures.
    $files = [__DIR__.'/../Fixtures/privacy/captured-account.json'];
    $hosts = [];
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($data)) as $value) {
            if (is_string($value)) {
                foreach (ohDearFixtureHosts($value) as $host) {
                    if ($host === 'gravatar.com' || str_ends_with($host, '.gravatar.com')) {
                        // Gravatar is public; the avatar path identifies the account owner's email.
                        $path = parse_url($value, PHP_URL_PATH);
                        if (is_string($path) && preg_match('~^/avatar/([a-f0-9]{32,64})(?:[./]|$)~i', $path, $matches) === 1) {
                            $hosts[] = strtolower($matches[1]);
                        }
                    } elseif ($host !== 'ohdear.app' && ! str_ends_with($host, '.ohdear.app')) {
                        $hosts[] = $host;
                    }
                }
            }
        }
    }
    $hosts = array_unique($hosts);
    foreach (ohDearFixtureStrings() as $file => $values) {
        $violations = 0;
        foreach ($values as $leaf) {
            foreach ($hosts as $host) {
                if (str_contains(strtolower($leaf['value']), $host)) {
                    $violations++;
                }
            }
        }
        expect($violations)->toBe(0, basename($file).': captured identifier count');
    }
});

arch('all Oh Dear fixture credentials use explicitly fake values', function (): void {
    $allowed = [
        'Authorization', 'bearer', 'Use your API token as Bearer token.',
        "OpenAPI 3.1 specification for the Oh Dear API, aligned with the public documentation and SDK. Authentication is via the HTTP Authorization Header using a Bearer token.\n",
        'abcd1234567890abcd1234567890abcd12345678', 'xoxp-2...', 'my-health-check-secret',
        'fake-health-secret-501', 'fake-health-secret-502', 'fake-health-secret-503', 'fake-health-secret-504', 'fake-health-secret-505',
        'fixture-health-secret', 'fixture-health-header-secret', 'fixture-crawler-secret', 'fixture-uptime-secret',
        'https://ping.ohdear.app/fixture-ping-secret', 'https://ping.ohdear.app/fixture-ping-secret-2',
    ];
    foreach (ohDearFixtureStrings() as $file => $values) {
        $violations = 0;
        foreach ($values as $leaf) {
            $value = $leaf['value'];
            if (($leaf['credential'] || preg_match('/\b(?:Bearer|Authorization)\b|(?<![a-f0-9])[a-f0-9]{32,}(?![a-f0-9])|(?:^|[\s"\'=])(?:[a-z0-9+\/]{40,}={0,2})(?=$|[\s"\'&])|\b(?:sk_(?:live|test)_|ghp_|github_pat_|AKIA|xox[baprs]-)|\beyJ[a-z0-9_-]+\.[a-z0-9_-]+\.[a-z0-9_-]+/i', $value)) && ! in_array($value, $allowed, true)) {
                $violations++;
            }
        }
        expect($violations)->toBe(0, basename($file).': non-fake credential pattern count');
    }
});
