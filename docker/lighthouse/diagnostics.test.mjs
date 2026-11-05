import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import cases from '../../tests/Fixtures/credential-parameters.json' with { type: 'json' };
import { redactDiagnostics } from './diagnostics.mjs';

void test('redacts 100 KB of overlapping secrets within bounded memory', () => {
    const secrets = Array.from({ length: 20 }, (_, index) =>
        'a'.repeat(index + 4),
    );
    const diagnostic = 'a'.repeat(100000);
    const memory = process.resourceUsage().maxRSS;

    const redacted = redactDiagnostics(diagnostic, secrets);

    const growth = (process.resourceUsage().maxRSS - memory) * 1024;
    console.info(`Redaction: ${growth} peak additional bytes`);
    assert.equal(redacted, '[redacted]');
    assert.ok(
        growth < 64 * 1024 * 1024,
        `Redaction exceeded 64 MiB: ${growth}`,
    );
});

for (const [
    name,
    secrets,
    diagnostic,
] of /** @type {Array<[string, string[], string]>} */ ([
    ['equal-length overlaps', ['abcdefgh', 'efghijkl'], 'abcdefghijkl'],
    ['longer right overlap', ['abcdefgh', 'fghijklmnop'], 'abcdefghijklmnop'],
    ['longer left overlap', ['abcdefghijkl', 'ijklmnop'], 'abcdefghijklmnop'],
    [
        'chained overlaps',
        ['abcdefgh', 'efghijkl', 'ijklmnop'],
        'abcdefghijklmnop',
    ],
    ['self overlaps', ['abcabcabc'], 'abcabcabcabcabc'],
    ['URI-encoded overlaps', ['abcd/efgh', 'efgh/ijkl'], 'abcd%2fefgh%2Fijkl'],
    ['form-encoded overlaps', ['abcd efgh', 'efgh ijkl'], 'abcd+efgh+ijkl'],
    ['JSON-escaped overlaps', ['abcd"efgh', 'efgh"ijkl'], 'abcd\\"efgh\\"ijkl'],
    ['decoded overlaps', ['alpha%20beta', 'betaGAMMA'], 'alpha betaGAMMA'],
])) {
    void test(`redacts the complete union of ${name}`, () => {
        for (const ordered of [secrets, [...secrets].reverse()]) {
            assert.equal(
                redactDiagnostics(
                    `Before ${diagnostic} | ${diagnostic} after`,
                    ordered,
                ),
                'Before [redacted] | [redacted] after',
            );
        }
    });
}

void test('redacts filtered and decoded secret forms before returning diagnostics', () => {
    assert.equal(
        redactDiagnostics(
            'Before CONTROLSECRET | DECODEDSECRET | DECODED SECRET after',
            ['CONTROL\nSECRET', 'DECODED%0ASECRET', 'DECODED%20SECRET'],
        ),
        'Before [redacted] | [redacted] | [redacted] after',
    );
});

void test('rejects non-string diagnostic text', () => {
    for (const text of [
        0,
        false,
        {},
        ['secret'],
        new String('secret'),
        null,
        undefined,
    ]) {
        assert.throws(() => redactDiagnostics(text, []), {
            name: 'TypeError',
            message: 'Diagnostic text must be a string.',
        });
    }
});

void test('rejects non-string diagnostic secrets with a stable error', () => {
    for (const secret of [
        new String('secret'),
        0,
        false,
        {},
        ['secret'],
        null,
        undefined,
    ]) {
        assert.throws(() => redactDiagnostics('Diagnostic text', [secret]), {
            name: 'TypeError',
            message: 'Diagnostic secrets must be strings.',
        });
    }
});

void test('preserves masks and the four-code-point minimum', () => {
    const secrets = ['long-redacted-token', 'dact', 'a%0Abc', '😀ab', '😀abc'];
    const redacted = redactDiagnostics(
        'long-redacted-token dact [redacted] abc 😀ab 😀abc',
        secrets,
    );
    assert.equal(
        redacted,
        '[redacted] [redacted] [redacted] abc 😀ab [redacted]',
    );
    assert.equal(redactDiagnostics(redacted, secrets), redacted);
});

for (const [name, [url, expected]] of Object.entries(cases)) {
    void test(`masks credential query names: ${name}`, () => {
        assert.equal(redactDiagnostics(url, []), expected);
    });
}
