<?php

declare(strict_types=1);

/** @param list<string> $paths */
function assertSanitizedFixtureHosts(array $paths): void
{
    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path) {
        $payload = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        array_walk_recursive($payload, function (mixed $value): void {
            if (! is_string($value)) {
                return;
            }
            preg_match_all('/(?<![a-z0-9-])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?![a-z0-9-])/i', $value, $matches);
            foreach ($matches[0] as $hostname) {
                if (($hostname === 'ohdear.app' && str_starts_with($value, 'https://ohdear.app/api/')) || ($hostname === 'fixture-sitemap.xml' && in_array($value, ['/fixture-sitemap.xml', 'fixture-sitemap.xml'], true))) {
                    continue;
                }
                expect(strtolower($hostname))->toEndWith('.example');
            }
        });
    }
}

it('keeps every hostname-shaped string leaf in real-shaped fixtures under example or the public upstream API', function (): void {
    $realFixtures = glob(base_path('tests/Fixtures/ohdear/real/*.json'));
    expect($realFixtures)->not->toBeEmpty();
    assertSanitizedFixtureHosts([...glob(base_path('addons/dns/tests/Fixtures/parity/*.json')), ...glob(base_path('addons/application_health/tests/Fixtures/*.json')), ...$realFixtures]);
});

it('rejects a planted private hostname in a real-shaped fixture', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'heptapod-fixture-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary fixture.');
    }
    try {
        file_put_contents($path, '{"sort_url":"https://private-host.invalid/path"}');
        expect(fn () => assertSanitizedFixtureHosts([$path]))->toThrow('ends with ".example"');
    } finally {
        unlink($path);
    }
});
