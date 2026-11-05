import { strict as assert } from 'node:assert';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { createScope, revokeScope } from './proxy-scope.mjs';

for (const scopeId of ['first', 'second']) {
    void test(`native private traffic and header isolation, scope ${scopeId}`, async () => {
        const token = await createScope({
            siteHost: 'fixture.test',
        });
        const fixture = spawn(process.execPath, [
            fileURLToPath(
                new URL(
                    '../../addons/lighthouse/tests/Support/origin-server.mjs',
                    import.meta.url,
                ),
            ),
            'fixture.test',
        ]);
        let output = '';
        fixture.stdout.setEncoding('utf8');
        fixture.stdout.on('data', (chunk) => (output += chunk));
        try {
            for (
                let attempts = 0;
                !/^\d+\n/.test(output) && attempts < 100;
                attempts++
            )
                await new Promise((resolve) => setTimeout(resolve, 50));
            assert.match(output, /^\d+\n/);
            const port = Number(output.split('\n')[0]);
            const response = await fetch('http://lighthouse:3000/lighthouse', {
                method: 'POST',
                body: JSON.stringify({
                    url: `http://fixture.test:${port}/redirect`,
                    options: {
                        desktop: true,
                        cpuSlowdownMultiplier: 2.5,
                        extraHeaders: {
                            'X-Lighthouse-Probe': 'origin-only-probe',
                        },
                    },
                    proxyToken: token,
                }),
            });
            assert.equal(response.status, 200);
            const report = await response.json();
            assert.equal(report.configSettings.formFactor, 'desktop');
            assert.equal(
                report.configSettings.throttling.cpuSlowdownMultiplier,
                2.5,
            );
            assert.equal(
                JSON.stringify(report).includes('origin-only-probe'),
                false,
            );
            for (const key of [
                'performance',
                'accessibility',
                'best-practices',
                'seo',
            ])
                assert.equal(typeof report.categories[key].score, 'number');
            assert.equal(output.includes('private-connect'), false);
            for (const marker of [
                'origin:expected',
                'same-origin-asset:expected',
                'redirect:absent',
                'other-port-asset:absent',
                'script-ran:absent',
            ])
                assert.ok(
                    output.includes(marker),
                    `missing ${marker}: ${output}`,
                );
            assert.equal(output.includes('unexpected'), false);
            assert.equal(output.includes('rtc-enabled'), false);
            for (const marker of [
                'private-asset:absent',
                'private-iframe:absent',
                'private-socket:absent',
            ]) {
                assert.equal(
                    output.includes(marker),
                    false,
                    `private resource reached: ${marker}`,
                );
            }
            for (const url of [
                'http://127.0.0.1:3128/health',
                'http://lighthouse-proxy:3128/scopes',
            ]) {
                const request = report.audits[
                    'network-requests'
                ].details.items.find((item) => item.url === url);
                assert.ok(request, `missing protected listener attempt ${url}`);
                assert.notEqual(request.statusCode, 200);
            }
        } finally {
            fixture.kill();
            await once(fixture, 'exit');
            await revokeScope(token);
        }
    });
}
void test('native redirect to a reachable private literal is denied before connecting', async () => {
    const token = await createScope({
        siteHost: 'fixture.test',
    });
    const fixture = spawn(process.execPath, [
        fileURLToPath(
            new URL(
                '../../addons/lighthouse/tests/Support/origin-server.mjs',
                import.meta.url,
            ),
        ),
        'fixture.test',
    ]);
    let output = '';
    fixture.stdout.setEncoding('utf8');
    fixture.stdout.on('data', (chunk) => (output += chunk));
    try {
        for (
            let attempts = 0;
            !/^\d+\n/.test(output) && attempts < 100;
            attempts++
        )
            await new Promise((resolve) => setTimeout(resolve, 50));
        assert.match(output, /^\d+\n/);
        const port = Number(output.split('\n')[0]);
        const response = await fetch('http://lighthouse:3000/lighthouse', {
            method: 'POST',
            body: JSON.stringify({
                url: `http://fixture.test:${port}/private-redirect`,
                proxyToken: token,
            }),
        });
        // A blocked navigation is the target's load failure (typed runtime error), not an audit service failure.
        assert.equal(response.status, 200);
        const report = await response.json();
        assert.equal(typeof report.runtimeError?.code, 'string');
        assert.equal(typeof report.target_host, 'string');
        const verdict = await fetch(
            `http://lighthouse-proxy-control:3129/scopes/${token}?host=${encodeURIComponent(report.target_host)}`,
        );
        assert.equal(verdict.status, 200);
        const cause = await verdict.json();
        assert.equal(cause.blockedTarget, true);
        assert.equal(cause.targetClass, 'private_network');
        assert.equal(typeof cause.targetAddress, 'string');
        assert.ok(output.includes('origin:absent'));
        assert.equal(output.includes('private-connect'), false);
    } finally {
        fixture.kill();
        await once(fixture, 'exit');
        await revokeScope(token);
    }
});
