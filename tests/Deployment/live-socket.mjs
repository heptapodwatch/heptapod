// Run against development Compose or the disposable production nginx stack.
import { spawn } from 'node:child_process';
import { randomInt } from 'node:crypto';
import { request } from 'node:http';
import assert from 'node:assert/strict';

const origin = process.env.LIVE_SOCKET_ORIGIN ?? 'http://app';
const hostname = process.env.LIVE_SOCKET_HOST ?? 'localhost';
const browserOrigin =
    process.env.LIVE_SOCKET_BROWSER_ORIGIN ?? 'http://localhost';
const host = { Host: hostname };
const cookies = new Map();
let expectingTermination = false;

function artisan(execute) {
    return new Promise((resolve, reject) => {
        const compose = process.env.LIVE_SOCKET_COMPOSE_ENV;
        const php = spawn(compose ? 'docker' : 'php', [
            ...(compose
                ? ['compose', '--env-file', compose, 'exec', '-T', 'app', 'php']
                : []),
            'artisan',
            'tinker',
            '--no-interaction',
            '--execute',
            execute,
        ]);
        let stdout = '';
        let stderr = '';
        php.stdout.on('data', (chunk) => {
            stdout += chunk;
        });
        php.stderr.on('data', (chunk) => {
            stderr += chunk;
        });
        php.on('error', reject);
        php.on('close', (code) =>
            code === 0 ? resolve(stdout) : reject(new Error(stderr)),
        );
    });
}

function fetchApp(path, options = {}) {
    return new Promise((resolve, reject) => {
        const client = request(new URL(path, origin), options, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => {
                const headers = new Headers();
                for (const [name, value] of Object.entries(response.headers)) {
                    for (const part of Array.isArray(value) ? value : [value]) {
                        if (part !== undefined) headers.append(name, part);
                    }
                }
                resolve(
                    new Response(Buffer.concat(chunks), {
                        status: response.statusCode,
                        headers,
                    }),
                );
            });
        });
        client.on('error', reject);
        client.end(options.body?.toString());
    });
}

function remember(response) {
    for (const cookie of response.headers.getSetCookie()) {
        const pair = cookie.split(';')[0];
        const separator = pair.indexOf('=');
        cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
    }
}

function headers() {
    return {
        ...host,
        Cookie: [...cookies]
            .map(([name, value]) => `${name}=${value}`)
            .join('; '),
        'X-XSRF-TOKEN': decodeURIComponent(cookies.get('XSRF-TOKEN')),
    };
}

function message(socket, accepts) {
    return new Promise((resolve, reject) => {
        let lastEvent = 'none';
        const timeout = setTimeout(() => {
            socket.removeEventListener('message', onMessage);
            reject(
                new Error(
                    `Timed out waiting for a Reverb message (last event: ${lastEvent})`,
                ),
            );
        }, 5000);
        function onMessage(event) {
            const packet = JSON.parse(event.data);
            lastEvent = JSON.stringify(packet);
            if (!accepts(packet)) return;
            clearTimeout(timeout);
            socket.removeEventListener('message', onMessage);
            resolve({ packet, receivedAt: Date.now() });
        }
        socket.addEventListener('message', onMessage);
    });
}

async function connect(key) {
    const socket = new WebSocket(
        `${origin.replace(/^http/, 'ws')}/app/${key}?protocol=7&client=js&version=8.6`,
        { headers: { Origin: browserOrigin } },
    );
    const established = message(
        socket,
        (packet) => packet.event === 'pusher:connection_established',
    );
    socket.addEventListener('error', (error) => {
        if (!expectingTermination)
            console.error('WebSocket error:', error.error ?? error);
    });
    const { packet } = await established;
    return { socket, socketId: JSON.parse(packet.data).socket_id };
}

const loginPage = await fetchApp('/login', { headers: host });
assert.equal(loginPage.status, 200);
remember(loginPage);
const meta = (await loginPage.text()).match(
    /<meta name="heptapod-reverb" content="([^"]*)">/,
);
assert.ok(meta, 'Runtime HTML must include the public Reverb key');
const { key } = JSON.parse(meta[1].replaceAll('&quot;', '"'));
const login = await fetchApp('/login', {
    method: 'POST',
    redirect: 'manual',
    headers: {
        ...headers(),
        'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
        email: process.env.LIVE_SOCKET_EMAIL ?? 'test@example.test',
        password: process.env.LIVE_SOCKET_PASSWORD ?? 'password',
    }),
});
assert.equal(login.status, 302);
remember(login);

const appId = process.env.LIVE_SOCKET_APP_ID ?? process.env.REVERB_APP_ID;
assert.ok(appId, 'Expected a configured Reverb app ID');
const unsigned = await fetchApp(`/apps/${appId}/events`, {
    method: 'POST',
    headers: { ...host, 'Content-Type': 'application/json' },
    redirect: 'manual',
    body: '{}',
});
assert.equal(
    unsigned.status,
    404,
    'Public /apps publishing must be inaccessible',
);

console.log('Proof: connected login; checking unsigned subscription');
const { socket: deniedSocket } = await connect(key);
try {
    const denied = message(
        deniedSocket,
        (packet) => packet.event === 'pusher:error',
    );
    deniedSocket.send(
        JSON.stringify({
            event: 'pusher:subscribe',
            data: { channel: 'presence-instance' },
        }),
    );
    await denied;
} finally {
    deniedSocket.close();
}

const clientId = Number(
    (
        await artisan(
            'echo Heptapod\\Models\\Client::query()->firstOrFail()->id;',
        )
    ).trim(),
);
assert.ok(Number.isSafeInteger(clientId), 'Expected a seeded client');
for (const legacyChannel of [
    'private-instance',
    `private-client.${clientId}`,
]) {
    const deniedAuthorization = await fetchApp('/broadcasting/auth', {
        method: 'POST',
        headers: {
            ...headers(),
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        body: new URLSearchParams({
            socket_id: '1.1',
            channel_name: legacyChannel,
        }),
    });
    assert.equal(
        deniedAuthorization.status,
        403,
        `${legacyChannel} must never receive a private signature`,
    );
    const { socket: legacySocket } = await connect(key);
    try {
        const denied = message(
            legacySocket,
            (packet) => packet.event === 'pusher:error',
        );
        legacySocket.send(
            JSON.stringify({
                event: 'pusher:subscribe',
                data: { channel: legacyChannel },
            }),
        );
        await denied;
    } finally {
        legacySocket.close();
    }
}
console.log(
    'PASS: legacy private instance and client subscriptions denied (HTTP 403 and socket error)',
);

console.log('Proof: connecting authorized socket');
const { socket, socketId } = await connect(key);
try {
    const authorization = await fetchApp('/broadcasting/auth', {
        method: 'POST',
        headers: {
            ...headers(),
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        redirect: 'manual',
        body: new URLSearchParams({
            socket_id: socketId,
            channel_name: 'presence-instance',
        }),
    });
    assert.equal(
        authorization.status,
        200,
        'Owner must authorize through Laravel',
    );
    const subscribed = message(
        socket,
        (packet) => packet.event === 'pusher_internal:subscription_succeeded',
    );
    socket.send(
        JSON.stringify({
            event: 'pusher:subscribe',
            data: {
                channel: 'presence-instance',
                ...(await authorization.json()),
            },
        }),
    );
    await subscribed;
    console.log('Proof: presence subscribed');

    if (process.env.LIVE_SOCKET_REVOKE_PROOF !== '1') {
        const proofId = randomInt(1, 2 ** 31);
        const received = message(
            socket,
            (packet) =>
                packet.event === 'LiveSignal' &&
                JSON.parse(packet.data).ids.proof === proofId,
        );
        const published = new Promise((resolve, reject) => {
            const compose = process.env.LIVE_SOCKET_COMPOSE_ENV;
            const php = spawn(compose ? 'docker' : 'php', [
                ...(compose
                    ? [
                          'compose',
                          '--env-file',
                          compose,
                          'exec',
                          '-T',
                          'app',
                          'php',
                      ]
                    : []),
                'artisan',
                'tinker',
                '--no-interaction',
                '--execute',
                `DB::transaction(function () { DB::afterCommit(static function (): void { echo "COMMITTED_AT=".microtime(true).PHP_EOL; }); Heptapod\\Events\\LiveSignal::forSite(Heptapod\\Models\\Site::query()->firstOrFail(), ['proof' => ${proofId}]); });`,
            ]);
            let stdout = '';
            let stderr = '';
            php.stdout.on('data', (chunk) => {
                stdout += chunk;
            });
            php.stderr.on('data', (chunk) => {
                stderr += chunk;
            });
            php.on('error', reject);
            php.on('close', (code) =>
                code === 0 ? resolve(stdout) : reject(new Error(stderr)),
            );
        });
        const [{ packet, receivedAt }, output] = await Promise.all([
            received,
            published,
        ]);
        const sentAt = Number(output.match(/COMMITTED_AT=([0-9.]+)/)?.[1]);
        assert.ok(Number.isFinite(sentAt), 'Commit timestamp must be present');
        assert.equal(JSON.parse(packet.data).topic, 'state.changed');
        assert.equal(JSON.parse(packet.data).ids.proof, proofId);
        const elapsed = receivedAt - sentAt * 1000;
        // PHP's commit clock and Node's receipt clock can differ across the Docker VM.
        const clockSkewAllowanceMs = 100;
        assert.ok(
            elapsed >= -clockSkewAllowanceMs &&
                elapsed + clockSkewAllowanceMs < 1000,
            `Expected sub-second commit-to-socket delivery with ±${clockSkewAllowanceMs} ms clock skew; got ${elapsed.toFixed(1)} ms`,
        );
        console.log(
            `PASS: unsigned publishing and presence subscription denied; Laravel-authorized subscription received correlated post-commit signal in ${elapsed.toFixed(1)} ms (±${clockSkewAllowanceMs} ms clock skew) through the proxy and Horizon`,
        );
    }
    if (process.env.LIVE_SOCKET_REVOKE_PROOF === '1') {
        const email = JSON.stringify(
            process.env.LIVE_SOCKET_EMAIL ?? 'test@example.test',
        );
        let temporaryOwner;
        try {
            const output = await artisan(
                `if (! app()->environment(['local', 'testing'])) { throw new LogicException('Revocation proof requires a disposable stack.'); } $actor = Heptapod\\Models\\User::factory()->create(); $actor->assignRole('Owner'); echo 'TEMP_OWNER='.$actor->id;`,
            );
            temporaryOwner = Number(output.match(/TEMP_OWNER=(\d+)/)?.[1]);
            assert.ok(temporaryOwner, `Temporary Owner ID missing: ${output}`);
            const close = new Promise((resolve, reject) => {
                const timeout = setTimeout(
                    () =>
                        reject(
                            new Error('Revoked Reverb socket remained open'),
                        ),
                    5000,
                );
                socket.addEventListener(
                    'close',
                    () => {
                        clearTimeout(timeout);
                        resolve();
                    },
                    { once: true },
                );
            });
            expectingTermination = true;
            await artisan(
                `$actor = Heptapod\\Models\\User::findOrFail(${temporaryOwner}); $member = Heptapod\\Models\\User::where('email', ${email})->sole(); app(Heptapod\\Actions\\ChangeMemberAccess::class)($actor, $member, ['role' => 'Member', 'client_ids' => [Heptapod\\Models\\Client::query()->firstOrFail()->id]]);`,
            );
            await close;
            const relogin = await fetchApp('/login', {
                method: 'POST',
                headers: {
                    ...headers(),
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    email: JSON.parse(email),
                    password: process.env.LIVE_SOCKET_PASSWORD ?? 'password',
                }),
            });
            assert.equal(relogin.status, 302);
            remember(relogin);
            const revoked = await fetchApp('/broadcasting/auth', {
                method: 'POST',
                headers: {
                    ...headers(),
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Accept: 'application/json',
                },
                body: new URLSearchParams({
                    socket_id: socketId,
                    channel_name: 'presence-instance',
                }),
            });
            assert.equal(
                revoked.status,
                403,
                'Revoked instance subscription must not reauthorize',
            );
            console.log(
                'PASS: real Reverb socket disconnected after scope revocation; reauthorized session denied instance channel (403)',
            );
        } finally {
            if (temporaryOwner) {
                await artisan(
                    `$actor = Heptapod\\Models\\User::findOrFail(${temporaryOwner}); $member = Heptapod\\Models\\User::where('email', ${email})->sole(); app(Heptapod\\Actions\\ChangeMemberAccess::class)($actor, $member, ['role' => 'Owner', 'client_ids' => []]); $actor->delete();`,
                );
            }
        }
    }
} finally {
    socket.close();
}
