import { strict as assert } from 'node:assert';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { request } from 'node:http';
import {
    mkdtemp,
    mkdir,
    writeFile,
    readFile,
    copyFile,
    rm,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, before, test } from 'node:test';

let server;
let rootDirectory;
let directory;
const token = 'a'.repeat(64);
const endpoint = 'http://localhost:3000';
const post = (payload) =>
    fetch(`${endpoint}/lighthouse`, {
        method: 'POST',
        body: JSON.stringify(
            payload === null ? null : { proxyToken: token, ...payload },
        ),
    });

before(async () => {
    rootDirectory = await mkdtemp(join(tmpdir(), 'lighthouse-http-'));
    directory = join(rootDirectory, 'docker/lighthouse');
    await mkdir(directory, { recursive: true });
    await mkdir(join(rootDirectory, 'config'));
    await copyFile(
        fileURLToPath(
            new URL('../../config/credential-parameters.json', import.meta.url),
        ),
        join(rootDirectory, 'config/credential-parameters.json'),
    );
    // Preserve relative module and policy imports. Only the external worker is substituted.
    await copyFile(
        fileURLToPath(new URL('./server.mjs', import.meta.url)),
        join(directory, 'server.mjs'),
    );
    await copyFile(
        fileURLToPath(new URL('./diagnostics.mjs', import.meta.url)),
        join(directory, 'diagnostics.mjs'),
    );
    await writeFile(
        join(directory, 'audit.mjs'),
        `
import { spawn } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import { writeSync } from 'node:fs';
const event = value => writeSync(3, JSON.stringify(value) + '\\n');
let input = '';
for await (const chunk of process.stdin) input += chunk;
const {url, options, proxyUrl} = JSON.parse(input);
await writeFile(new URL('./input.json', import.meta.url), JSON.stringify({url, options, proxyUrl, args: process.argv.slice(2)}));
const target = new URL(url);
const path = target.pathname;
if (path !== '/no-events') event({stage: 'navigation'});
if (path === '/no-events') { event({error: {code: 'TARGET_CRASHED', message: 'detail'}}); process.exit(1); }
if (path === '/fail') {
    event({error: {code: 'PROTOCOL_TIMEOUT', message: proxyUrl + ' token=' + new URL(proxyUrl).password + '\\u0000\\u001b\\u007f\\u009f\\n' + 'ü'.repeat(600)}});
    console.error('private-vendor-error');
    process.exit(1);
}
if (path === '/crossing-secret') { event({error: {code: 'TARGET_CRASHED', message: 'x'.repeat(490) + (options.extraHeaders.Authorization === 'safe' ? target.searchParams.get('token') : options.extraHeaders.Authorization)}}); process.exit(1); }
if (path === '/astral-boundary') { event({error: {code: 'TARGET_CRASHED', message: 'x'.repeat(499) + '😀tail'}}); process.exit(1); }
if (path === '/unsafe-unknown-after-safe') { event({stage: 'auditing', last_unrecognised_event: 'Alpha event'}); event({stage: 'auditing'}); event({error: {code: 'TARGET_CRASHED', message: 'detail'}}); process.exit(1); }
if (path === '/unknown-event') { event({stage: 'auditing', last_unrecognised_event: 'New vendor event'}); event({error: {code: 'TARGET_CRASHED', message: 'detail'}}); process.exit(1); }
if (path === '/crash') process.exit(1);
if (path === '/worker-page-crashed') { event({error: {code: 'TARGET_CRASHED', message: 'Renderer crashed'}}); process.exit(1); }
if (path === '/invalid-code') { event({error: {code: 'TARGET_CRASHED\\n', message: 'Invalid code'}}); process.exit(1); }
if (path === '/protocol-error') { event({error: {code: 'PROTOCOL_TIMEOUT', message: 'detail'}}); process.exit(1); }
if (path === '/code-only') { event({error: {code: 'CRI_TIMEOUT'}}); process.exit(1); }
if (path === '/lowercase-code') { event({error: {code: 'protocol_timeout', message: 'detail'}}); process.exit(1); }
if (path === '/channel-cap') {
    event({error: {code: 'PROTOCOL_TIMEOUT', message: 'Incomplete'}});
    event({stage: 'gathering'});
    event({ignored: 'x'.repeat(20000)});
    event({stage: 'auditing'});
    process.exit(1);
}
if (path === '/oversize') { process.stdout.write('x'.repeat(9 * 1024 * 1024)); await new Promise(() => {}); }
if (path === '/malformed') { console.log('not JSON'); process.exit(0); }
if (path === '/timeout') {
    await new Promise(resolve => setTimeout(resolve, 1100));
    event({stage: 'gathering'});
    const child = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], {stdio: 'ignore'});
    await writeFile(new URL('./pids.json', import.meta.url), JSON.stringify([process.pid, child.pid]));
    await new Promise(resolve => setTimeout(resolve, 200000));
}
if (path === '/slow') await new Promise(resolve => setTimeout(resolve, 2000));
console.log(JSON.stringify({
    lighthouseVersion: '13.5.0', audits: {'document-title': {score: 1}},
    categories: Object.fromEntries(['performance','accessibility','best-practices','seo'].map(key => [key, {score: path === '/invalid-score' ? 2 : 1}])),
    ...(path === '/load-timeout' ? {load_timed_out: true} : {}),
    ...(path === '/invalid-load-timeout' ? {load_timed_out: 'true'} : {}),
    ...(path === '/runtime-error' ? {runtimeError: {code: target.searchParams.get('code') ?? 'NO_FCP', message: 'Runtime detail'}} : {}),
    ...(path === '/tls-error' ? {runtimeError: {code: 'FAILED_DOCUMENT_REQUEST', message: 'Lighthouse was unable to reliably load the page (net::ERR_CERT_COMMON_NAME_INVALID)'}} : {}),
    ...(path === '/long-tls-error' ? {runtimeError: {code: 'FAILED_DOCUMENT_REQUEST', message: 'x'.repeat(600) + ' net::ERR_CERT_COMMON_NAME_INVALID'}} : {}),
    ...(path === '/blocked-target' ? {runtimeError: {code: 'FAILED_DOCUMENT_REQUEST'}, target_status_code: 403, target_host: '127.0.0.1'} : {}),
}));
`,
    );
    server = spawn(process.execPath, [join(directory, 'server.mjs')], {
        env: { ...process.env, CHROME_FLAGS: '--headless --no-sandbox' },
        stdio: 'inherit',
    });
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            if ((await fetch(`${endpoint}/health`)).ok) return;
        } catch (error) {
            if (error.cause?.code !== 'ECONNREFUSED') throw error;
        }
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error('Wrapper did not start');
});
after(async () => {
    if (server && server.exitCode === null) {
        server.kill();
        await once(server, 'exit');
    }
    await rm(rootDirectory, { recursive: true, force: true });
});

void test('returns complete report and forwards native settings only on stdin', async () => {
    const options = {
        desktop: true,
        cpuSlowdownMultiplier: 2.5,
        extraHeaders: { 'X-Probe': 'fixture-secret' },
    };
    const response = await post({ url: 'https://example.com/', options });
    assert.equal(response.status, 200);
    assert.equal((await response.json()).audits['document-title'].score, 1);
    assert.deepEqual(
        JSON.parse(await readFile(join(directory, 'input.json'), 'utf8')),
        {
            url: 'https://example.com/',
            options,
            proxyUrl: `http://run:${token}@lighthouse-proxy:3128`,
            args: [],
        },
    );
});
void test('retains the worker load timeout flag and leaves ordinary reports unflagged', async () => {
    const slow = await post({ url: 'https://example.com/load-timeout' });
    assert.equal(slow.status, 200);
    assert.equal((await slow.json()).load_timed_out, true);
    const normal = await post({ url: 'https://example.com/' });
    assert.equal(normal.status, 200);
    assert.ok(!Object.hasOwn(await normal.json(), 'load_timed_out'));
});
void test('rejects a non-boolean worker load timeout flag', async () => {
    const response = await post({
        url: 'https://example.com/invalid-load-timeout',
    });
    assert.equal(response.status, 502);
    assert.deepEqual(await response.json(), {
        error: 'Lighthouse audit did not complete',
    });
});
void test('retains the failed navigation host for the private-target verdict', async () => {
    const response = await post({ url: 'https://example.com/blocked-target' });
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), {
        runtimeError: { code: 'page_load_failed', message: null },
        target_status_code: 403,
        target_host: '127.0.0.1',
    });
});
void test('preserves a multibyte URL split across request chunks', async () => {
    const url = 'https://example.com/über';
    const body = Buffer.from(JSON.stringify({ url, proxyToken: token }));
    const split = body.indexOf(Buffer.from('ü')) + 1;
    const status = await new Promise((resolve, reject) => {
        const req = request(
            `${endpoint}/lighthouse`,
            { method: 'POST' },
            (res) => {
                res.resume();
                res.on('end', () => resolve(res.statusCode));
            },
        );
        req.on('error', reject);
        req.write(body.subarray(0, split));
        setTimeout(() => req.end(body.subarray(split)), 50);
    });
    assert.equal(status, 200);
    assert.equal(
        JSON.parse(await readFile(join(directory, 'input.json'), 'utf8')).url,
        url,
    );
});
void test('rejects invalid input and arbitrary worker settings', async () => {
    for (const payload of [
        null,
        {},
        { url: 'file:///etc/passwd' },
        { url: 'https://example.com', proxyToken: null },
        { url: 'https://example.com', proxyToken: 'guess' },
        {
            url: 'https://example.com',
            networkPolicy: {
                siteHost: 'example.com',
            },
        },
        { url: 'https://example.com', options: null },
        { url: 'https://example.com', options: { desktop: 'yes' } },
        {
            url: 'https://example.com',
            options: { chromeFlags: '--remote-debugging-port=1234' },
        },
        { url: `https://example.com/${'x'.repeat(4096)}` },
    ])
        assert.equal((await post(payload)).status, 400);
    assert.equal((await fetch(`${endpoint}/unknown`)).status, 404);
});
void test('returns 429 while auditing, retains health and releases busy', async () => {
    const pending = post({ url: 'https://example.com/slow' });
    await new Promise((resolve) => setTimeout(resolve, 100));
    assert.equal((await post({ url: 'https://example.com/' })).status, 429);
    assert.equal((await fetch(`${endpoint}/health`)).status, 200);
    assert.equal((await pending).status, 200);
    assert.equal((await post({ url: 'https://example.com/' })).status, 200);
});
void test('reports target load failures as a typed runtime error, not a service error', async () => {
    for (const [path, code, message] of [
        ['runtime-error?code=PAGE_HUNG', 'page_hung', 'Runtime detail'],
        ['runtime-error?code=TARGET_CRASHED', 'page_crashed', 'Runtime detail'],
        ['runtime-error?code=NO_FCP', 'no_content', 'Runtime detail'],
        ['runtime-error?code=NOT_HTML', 'not_html', 'Runtime detail'],
        ...[
            'FAILED_DOCUMENT_REQUEST',
            'ERRORED_DOCUMENT_REQUEST',
            'NO_DOCUMENT_REQUEST',
            'CHROME_INTERSTITIAL_ERROR',
            'INSECURE_DOCUMENT_REQUEST',
        ].map((code) => [
            `runtime-error?code=${code}`,
            'page_load_failed',
            'Runtime detail',
        ]),
        [
            'runtime-error?code=UNRECOGNIZED',
            'audit_incomplete',
            '[redacted]: Runtime detail',
        ],
        [
            'tls-error',
            'ERR_CERT_COMMON_NAME_INVALID',
            'Lighthouse was unable to reliably load the page (net::ERR_CERT_COMMON_NAME_INVALID)',
        ],
        ['long-tls-error', 'ERR_CERT_COMMON_NAME_INVALID', 'x'.repeat(500)],
    ]) {
        const response = await post({ url: `https://example.com/${path}` });
        assert.equal(response.status, 200);
        assert.deepEqual(await response.json(), {
            runtimeError: { code, message },
        });
    }
});
void test('sanitizes crashed and invalid-report workers, then accepts another audit', async () => {
    for (const path of ['crash', 'malformed']) {
        const response = await post({ url: `https://example.com/${path}` });
        assert.equal(response.status, 502);
        assert.deepEqual(await response.json(), {
            error: 'Lighthouse audit did not complete',
        });
    }
    assert.equal((await post({ url: 'https://example.com/' })).status, 200);
});
void test('redacts and bounds worker diagnostics without exposing stderr', async () => {
    const response = await post({ url: 'https://example.com/fail' });
    assert.equal(response.status, 200);
    const body = await response.json();
    assert.deepEqual(body, {
        auditFailure: {
            code: 'audit_incomplete',
            stage: 'navigation',
            stageSeconds: { navigation: 0 },
            message:
                'PROTOCOL_TIMEOUT: [redacted] token=[redacted]' +
                'ü'.repeat(455),
        },
    });
    assert.equal(body.auditFailure.message.length, 500);
});
for (const secret of [
    'HEADER-CROSSING-SECRET-unique',
    'QUERY-CROSSING-SECRET-unique',
]) {
    void test(`redacts secrets crossing the message cut: ${secret}`, async () => {
        const header = secret.startsWith('HEADER') ? secret : 'safe';
        const query = secret.startsWith('QUERY') ? secret : 'safe';
        const response = await post({
            url: 'https://example.com/crossing-secret?token=' + query,
            options: { extraHeaders: { Authorization: header } },
        });
        const body = await response.json();
        assert.equal(response.status, 200);
        for (let index = 0; index <= secret.length - 4; index++)
            assert.ok(
                !body.auditFailure.message.includes(
                    secret.slice(index, index + 4),
                ),
                body.auditFailure.message,
            );
    });
}
void test('redacts a control-character query secret crossing the message cut', async () => {
    const secret = 'CONTROLCROSSING-secret-value';
    const response = await post({
        url: 'https://example.com/crossing-secret?token=CONTROL%0ACROSSING-secret-value',
        options: { extraHeaders: { Authorization: 'safe' } },
    });
    const body = await response.json();
    assert.equal(response.status, 200);
    for (let index = 0; index <= secret.length - 4; index++) {
        assert.ok(
            !body.auditFailure.message.includes(secret.slice(index, index + 4)),
            body.auditFailure.message,
        );
    }
});
void test('does not redact secret forms shorter than four code points', async () => {
    for (const [query, header] of [
        ['a%0Abc', 'safe'],
        ['safe', 'abc'],
    ]) {
        const response = await post({
            url: 'https://example.com/crossing-secret?token=' + query,
            options: { extraHeaders: { Authorization: header } },
        });
        assert.equal(
            (await response.json()).auditFailure.message,
            'x'.repeat(490) + 'abc',
        );
    }
});
void test('retains a null stage when no stage events arrive', async () => {
    const response = await post({ url: 'https://example.com/no-events' });
    assert.equal((await response.json()).auditFailure.stage, null);
});
void test('cuts diagnostics by code points without splitting an astral character', async () => {
    const response = await post({ url: 'https://example.com/astral-boundary' });
    assert.equal(
        (await response.json()).auditFailure.message,
        'x'.repeat(499) + '😀',
    );
});
void test('forwards the separate unrecognised event fact from fd 3', async () => {
    const response = await post({ url: 'https://example.com/unknown-event' });
    assert.equal(
        (await response.json()).auditFailure.last_unrecognised_event,
        'New vendor event',
    );
});
void test('retains the last safe unrecognised name after an unsafe unknown event', async () => {
    const secret = 'SUPPLIED-HEADER-CREDENTIAL';
    const response = await post({
        url: 'https://example.com/unsafe-unknown-after-safe',
        options: { extraHeaders: { Authorization: secret } },
    });
    const body = await response.json();
    assert.equal(body.auditFailure.stage, 'auditing');
    assert.equal(body.auditFailure.last_unrecognised_event, 'Alpha event');
    for (let index = 0; index <= secret.length - 4; index++) {
        assert.ok(
            !JSON.stringify(body).includes(secret.slice(index, index + 4)),
        );
    }
});
void test('names worker crashes, unscored reports and oversize reports', async () => {
    for (const [path, code, message] of [
        ['worker-page-crashed', 'page_crashed', 'Renderer crashed'],
        ['invalid-code', 'audit_incomplete', 'Invalid code'],
        ['protocol-error', 'audit_incomplete', 'PROTOCOL_TIMEOUT: detail'],
        ['code-only', 'audit_incomplete', 'CRI_TIMEOUT'],
        ['lowercase-code', 'audit_incomplete', 'detail'],
        [
            'invalid-score',
            'report_unscored',
            'performance, accessibility, best-practices, seo',
        ],
        ['oversize', 'report_too_large', null],
    ]) {
        const response = await post({ url: `https://example.com/${path}` });
        assert.equal(response.status, 200);
        assert.deepEqual(await response.json(), {
            auditFailure: {
                code,
                stage: 'navigation',
                stageSeconds: { navigation: 0 },
                message,
            },
        });
    }
});
void test('drops diagnostics beyond 16 KiB while retaining the last complete stage', async () => {
    const response = await post({ url: 'https://example.com/channel-cap' });
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), {
        auditFailure: {
            code: 'audit_incomplete',
            stage: 'gathering',
            stageSeconds: { navigation: 0, gathering: 0 },
            message: 'PROTOCOL_TIMEOUT: Incomplete',
        },
    });
});
void test(
    'kills worker and descendants at the 150-second deadline and releases busy',
    { timeout: 160000 },
    async () => {
        const start = Date.now();
        const response = await post({ url: 'https://example.com/timeout' });
        assert.equal(response.status, 200);
        const body = await response.json();
        assert.equal(body.auditFailure.budgetSeconds, 150);
        const times = body.auditFailure.stageSeconds;
        assert.deepEqual(Object.keys(times), ['navigation', 'gathering']);
        for (const seconds of Object.values(times)) {
            assert.ok(
                Number.isInteger(seconds) && seconds >= 0 && seconds <= 155,
            );
        }
        assert.equal(times.navigation, 1);
        assert.ok(times.gathering >= 145 && times.gathering <= 150);
        assert.deepEqual(body, {
            auditFailure: {
                code: 'audit_budget_exceeded',
                stage: 'gathering',
                message: null,
                budgetSeconds: 150,
                stageSeconds: times,
            },
        });
        assert.ok(Date.now() - start >= 150000);
        assert.ok(Date.now() - start < 155000);
        for (const pid of JSON.parse(
            await readFile(join(directory, 'pids.json'), 'utf8'),
        )) {
            try {
                process.kill(pid, 0);
                assert.match(
                    await readFile(`/proc/${pid}/stat`, 'utf8'),
                    /\) Z /,
                );
            } catch (error) {
                if (error.code !== 'ESRCH' && error.code !== 'ENOENT')
                    throw error;
            }
        }
        assert.equal((await post({ url: 'https://example.com/' })).status, 200);
    },
);
