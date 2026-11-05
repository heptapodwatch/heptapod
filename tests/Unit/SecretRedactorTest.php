<?php

declare(strict_types=1);

use Heptapod\Contracts\SecretRedactor;

it('redacts case variants without rewriting the mask and handles UTF-8 and opaque bytes', function (string $secret, string $echo): void {
    expect(SecretRedactor::redact('Rejected '.$echo, [$secret, 'redacted']))->toBe('Rejected [redacted]');
})->with([
    'overlapping values and mask' => ['long-redacted-token', 'LONG-REDACTED-TOKEN'],
    'Unicode with ASCII case variance' => ['schlüssel-äöü', 'SCHLüSSEL-äöü'],
    'opaque bytes' => ["token-\xff", "TOKEN-\xff"],
]);

it('redacts large and numerous secrets within bounded memory', function (array $secrets, string $diagnostic, string $expected): void {
    expect(SecretRedactor::redact('unrelated diagnostic', $secrets))->toBe('unrelated diagnostic');
    memory_reset_peak_usage();
    $memory = memory_get_usage(true);

    $redacted = SecretRedactor::redact($diagnostic, $secrets);

    $growth = memory_get_peak_usage(true) - $memory;
    fwrite(STDOUT, sprintf("Redaction: %d peak additional bytes\n", $growth));
    expect($redacted)->toBe($expected);
    expect($growth)->toBeLessThan(32 * 1024 * 1024);
})->with([
    '100 KB secret' => fn (): array => [[str_repeat('x', 100000)], str_repeat('x', 100000), '[redacted]'],
    '1000 distinct 100-byte secrets' => function (): array {
        $secrets = array_map(fn (int $index): string => str_pad('credential-'.$index.'-', 100, 'x'), range(1, 1000));

        return [$secrets, implode('|', $secrets), implode('|', array_fill(0, 1000, '[redacted]'))];
    },
    '20 overlapping secrets in 100 KB' => fn (): array => [array_map(fn (int $length): string => str_repeat('a', $length), range(4, 23)), str_repeat('a', 100000), '[redacted]'],
]);

it('does not rewrite newly inserted or pre-existing masks', function (): void {
    $secrets = ['long-private-token', 'dact'];
    $redacted = SecretRedactor::redact('LONG-PRIVATE-TOKEN dact [redacted]', $secrets);
    expect($redacted)->toBe('[redacted] [redacted] [redacted]')
        ->and(SecretRedactor::redact($redacted, $secrets))->toBe($redacted);
});

it('rejects non-string secret leaves at both redaction entry points', function (mixed $secret): void {
    $secrets = ['nested' => [$secret]];

    expect(fn (): string => SecretRedactor::redact('Diagnostic text', $secrets))
        ->toThrow(InvalidArgumentException::class, 'Redaction secrets must be strings.');
    expect(fn (): mixed => SecretRedactor::redactValues(['message' => 'Diagnostic text'], $secrets))
        ->toThrow(InvalidArgumentException::class, 'Redaction secrets must be strings.');
    expect(fn (): mixed => SecretRedactor::redactValues(null, $secrets))
        ->toThrow(InvalidArgumentException::class, 'Redaction secrets must be strings.');
})->with([
    'null' => [null],
    'integer' => [1234],
    'boolean' => [false],
    'object' => [new stdClass],
]);

it('leaves short legacy secrets and non-ASCII case variants unchanged', function (): void {
    expect(SecretRedactor::redact('a ab abc SCHLÜSSEL-ÄÖÜ', ['a', 'ab', 'abc', 'schlüssel-äöü']))->toBe('a ab abc SCHLÜSSEL-ÄÖÜ');
});

it('masks credential-named query values by decoded name while other parameters stay readable', function (string $url, string $expected): void {
    expect(SecretRedactor::redact($url, []))->toBe($expected);
})->with(json_decode(file_get_contents(__DIR__.'/../Fixtures/credential-parameters.json'), true, flags: JSON_THROW_ON_ERROR));
