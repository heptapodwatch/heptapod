import { strict as assert } from 'node:assert';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { UIStrings } from 'lighthouse/core/gather/driver/navigation.js';

const vendorSource = (path) =>
    readFile(fileURLToPath(import.meta.resolve(path)), 'utf8');

// This is a dependency contract: renamed vendor events must force a mapping review on upgrade.
void test('maps the pinned Lighthouse status events to private stage enums', async () => {
    assert.equal(
        JSON.parse(await vendorSource('lighthouse/package.json')).version,
        '13.5.0',
    );
    assert.equal(
        JSON.parse(await vendorSource('lighthouse-logger/package.json'))
            .version,
        '2.0.2',
    );
    const cases = [
        [
            'lighthouse/core/gather/driver.js',
            /msg: '([^']*Connecting to browser)'/,
            'browser_launch',
        ],
        [
            'lighthouse/core/gather/driver/navigation.js',
            /msg: `([^`]*Navigating to )\$\{requestor\}`/,
            'navigation',
        ],
        [
            'lighthouse/core/gather/driver/prepare.js',
            /msg: '(Preparing target for navigation mode)'/,
            'navigation',
        ],
        [
            'lighthouse/core/gather/driver/prepare.js',
            /msg: '(Preparing network conditions)'/,
            'navigation',
        ],
        [
            'lighthouse/core/gather/runner-helpers.js',
            /msg: `(Getting artifact: )\$\{artifactDefn.id\}`/,
            'gathering',
        ],
        [
            'lighthouse/core/runner.js',
            /msg: '(Analyzing and running audits\.\.\.)'/,
            'auditing',
        ],
        ['lighthouse/core/runner.js', /msg: `(Auditing: )\$\{/, 'auditing'],
        ['lighthouse/core/runner.js', /msg: '(Gather phase)'/, 'gathering'],
        ['lighthouse/core/runner.js', /msg: '(Audit phase)'/, 'auditing'],
        [
            'lighthouse/core/runner.js',
            /msg: '(Generating results\.\.\.)'/,
            'reporting',
        ],
    ];
    for (const [path, pattern, stage] of cases) {
        const match = pattern.exec(await vendorSource(path));
        assert.ok(match, `Pinned event changed in ${path}`);
        const message =
            match[1] +
            'https://private.example.test/?secret=not-for-the-channel';
        const result = await runWorker(message);
        assert.equal(result.code, 1);
        assert.deepEqual(result.events.at(-2), { stage });
        assert.deepEqual(result.events.at(-1), {
            error: { code: 'PROTOCOL_TIMEOUT', message: 'Owned error detail' },
        });
        assert.equal(result.stdout, '');
        assert.equal(result.stderr, '');
        assert.ok(
            !JSON.stringify(result.events).includes('private.example.test'),
        );
    }
    const traceSource = await vendorSource(
        'lighthouse/core/computed/trace-engine-result.js',
    );
    assert.match(traceSource, /msg: `Trace Engine \$\{id\}`/);
    const processorSource = await vendorSource(
        '@paulirish/trace_engine/models/trace/Processor.js',
    );
    for (const id of ['total', 'parse', 'insights']) {
        assert.ok(
            processorSource.includes(`logger?.start('${id}')`),
            `Pinned trace event changed: ${id}`,
        );
        const result = await runWorker(`Trace Engine ${id}`);
        assert.deepEqual(result.events.at(-2), { stage: 'gathering' });
    }
    for (const message of [
        'Trace Engine parse:handleEvent',
        'Trace Engine insights:Viewport',
    ]) {
        assert.deepEqual((await runWorker(message)).events.at(-2), {
            stage: 'gathering',
        });
    }
    const neutralCases = [
        [
            'lighthouse/core/computed/computed-artifact.js',
            /msg: `(Computing artifact: )\$\{computedName\}/,
        ],
        [
            'lighthouse/core/gather/driver/storage.js',
            /msg: '(Cleaning browser cache)'/,
        ],
        [
            'lighthouse/core/gather/driver/storage.js',
            /msg: '(Cleaning origin data)'/,
        ],
        [
            'lighthouse/core/gather/driver/environment.js',
            /msg: '(Benchmarking machine)'/,
        ],
        [
            'lighthouse/core/gather/driver/environment.js',
            /msg: '(Getting browser version)'/,
        ],
        [
            'lighthouse/core/gather/driver/environment.js',
            /msg: '(Host device pixel ratio)'/,
        ],
        [
            'lighthouse/core/gather/gatherers/stacks.js',
            /msg: '(Collect stacks)'/,
        ],
        ['lighthouse/core/config/config.js', /msg: '(Initialize config)'/],
        [
            'lighthouse/core/config/config.js',
            /msg: '(Resolve artifact definitions)'/,
        ],
    ];
    for (const [path, pattern] of neutralCases) {
        const match = pattern.exec(await vendorSource(path));
        assert.ok(match, 'Pinned neutral event changed in ' + path);
        const result = await runWorker([
            'Getting artifact: Trace',
            match[1] + (match[1].endsWith(': ') ? 'Trace' : ''),
        ]);
        assert.deepEqual(result.events.at(-2), { stage: 'gathering' });
    }
});
void test('keeps the last stage and reports only the bounded leading unrecognised event name', async () => {
    assert.deepEqual(
        (
            await runWorker([
                'Auditing: Layout',
                'New vendor event: https://private.example.test/?token=secret',
            ])
        ).events.at(-2),
        { stage: 'auditing', last_unrecognised_event: 'New vendor event' },
    );
    assert.deepEqual(
        (
            await runWorker([
                'New event https://private.example.test/?token=secret',
            ])
        ).events.at(-2),
        { stage: 'browser_launch', last_unrecognised_event: 'New event https' },
    );
    assert.deepEqual(
        (await runWorker(['X'.repeat(80) + ': private'])).events.at(-2),
        { stage: 'browser_launch', last_unrecognised_event: 'X'.repeat(64) },
    );
    assert.deepEqual(
        (
            await runWorker([
                'Auditing: Layout',
                'header-secret-value: private',
            ])
        ).events.at(-2),
        { stage: 'auditing' },
    );
    const unsafeAfterSafe = await runWorker([
        'Auditing: Layout',
        'Alpha event',
        'header-secret-value: private',
    ]);
    assert.deepEqual(unsafeAfterSafe.events.at(-3), {
        stage: 'auditing',
        last_unrecognised_event: 'Alpha event',
    });
    assert.deepEqual(unsafeAfterSafe.events.at(-2), { stage: 'auditing' });
    const dottedAfterSafe = await runWorker([
        'Auditing: Layout',
        'Alpha event',
        'Fetching robots for example.com',
    ]);
    assert.deepEqual(dottedAfterSafe.events.at(-2), { stage: 'auditing' });
    assert.ok(!JSON.stringify(dottedAfterSafe.events).includes('example'));
    assert.ok(
        !JSON.stringify(unsafeAfterSafe.events).includes('header-secret'),
    );
    assert.deepEqual((await runWorker([])).events.at(-2), {
        stage: 'browser_launch',
    });
});

void test('keeps gathering after a computed-artifact event', async () => {
    assert.deepEqual(
        (
            await runWorker([
                'Getting artifact: Trace',
                'Computing artifact: Trace',
            ])
        ).events.at(-2),
        { stage: 'gathering' },
    );
});

void test('reports browser launch errors through fd 3 before exiting', async () => {
    const result = await runWorker(null);
    assert.equal(result.code, 1);
    assert.deepEqual(result.events, [
        { stage: 'browser_launch' },
        { error: { code: 'TARGET_CRASHED', message: 'Launch failed' } },
    ]);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr, '');
});

async function runWorker(message, report = null) {
    const directory = await mkdtemp(join(tmpdir(), 'lighthouse-stage-'));
    try {
        // Substitute only external Lighthouse/browser calls; execute the shipped worker and real logger.
        const lighthouseStub = `
            import log from ${JSON.stringify(import.meta.resolve('lighthouse-logger'))};
            export const defaultConfig = {}, desktopConfig = {};
            export default async function () {
                log.setLevel('silent');
                for (const message of ${JSON.stringify(message === null ? [] : Array.isArray(message) ? message : [message])}) log.log('status', message);
                if (${JSON.stringify(report)} !== null) return {lhr: ${JSON.stringify(report)}};
                throw Object.assign(new Error('Internal detail'), {code: 'PROTOCOL_TIMEOUT', friendlyMessage: 'Owned error detail'});
            }
        `;
        const browserStub = `
            export async function launchBrowser() {
                ${message === null ? "throw Object.assign(new Error('Launch failed'), {code: 'TARGET_CRASHED'});" : ''}
                const page = {setBypassServiceWorker: async () => {}, evaluateOnNewDocument: async () => {}, setRequestInterception: async () => {}, on: () => {}};
                return {browser: {newPage: async () => page}, close: async () => {}};
            }
        `;
        const preload = join(directory, 'external-seams.mjs');
        await writeFile(
            preload,
            `
            import {registerHooks} from 'node:module';
            registerHooks({resolve(specifier, context, nextResolve) {
                if (specifier === 'lighthouse') return {url: ${JSON.stringify('data:text/javascript,' + encodeURIComponent(lighthouseStub))}, shortCircuit: true};
                if (specifier === './browser.mjs') return {url: ${JSON.stringify('data:text/javascript,' + encodeURIComponent(browserStub))}, shortCircuit: true};
                return nextResolve(specifier, context);
            }});
        `,
        );
        const child = spawn(
            process.execPath,
            [
                '--import',
                preload,
                fileURLToPath(new URL('./audit.mjs', import.meta.url)),
            ],
            { stdio: ['pipe', 'pipe', 'pipe', 'pipe'] },
        );
        const output = [[], [], []];
        [child.stdout, child.stderr, child.stdio[3]].forEach((stream, index) =>
            stream.on('data', (chunk) => output[index].push(chunk)),
        );
        child.stdin.end(
            JSON.stringify({
                url: 'https://example.test/',
                options: {
                    extraHeaders: { Authorization: 'header-secret-value' },
                },
                proxyUrl: 'http://proxy.test/',
            }),
        );
        const code = await new Promise((resolve, reject) => {
            child.once('error', reject);
            child.once('close', resolve);
        });
        return {
            code,
            stdout: Buffer.concat(output[0]).toString(),
            stderr: Buffer.concat(output[1]).toString(),
            events: Buffer.concat(output[2])
                .toString()
                .trim()
                .split('\n')
                .map((line) => JSON.parse(line)),
        };
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
}

void test('flags the pinned Lighthouse load-timeout warning without flagging other reports', async () => {
    assert.equal(
        UIStrings.warningTimeout,
        'The page loaded too slowly to finish within the time limit. Results may be incomplete.',
    );
    for (const warnings of [
        [UIStrings.warningTimeout],
        [],
        ['Other warning'],
    ]) {
        const result = await runWorker([], { runWarnings: warnings });
        assert.equal(result.code, 0);
        const report = JSON.parse(result.stdout);
        if (warnings.includes(UIStrings.warningTimeout))
            assert.equal(report.load_timed_out, true);
        else assert.ok(!Object.hasOwn(report, 'load_timed_out'));
    }
});
