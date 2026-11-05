import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import net from 'node:net';
import { launchBrowser } from '/service/browser.mjs';

let input = '';
for await (const chunk of process.stdin) input += chunk;
const tokens = JSON.parse(input);

for (const scopeId of ['first', 'second']) {
    void test(`native dedicated/shared/service worker traffic, scope ${scopeId}`, async () => {
        assert.equal(
            (await fetch('http://lighthouse-proxy:4000/reset')).status,
            200,
        );
        const token = tokens[scopeId];
        const { browser, localProxy, close } = await launchBrowser(
            `http://run:${token}@lighthouse-proxy:3128`,
        );
        try {
            assert.equal(
                browser
                    .process()
                    .spawnargs.includes('--disable-popup-blocking'),
                false,
            );
            const page = await browser.newPage();
            await page.goto('http://localhost:4000/');
            const completed = await page.evaluate(async () => {
                const dedicated = await new Promise((resolve) => {
                    const worker = new Worker('/dedicated.js');
                    worker.onmessage = (e) => {
                        worker.terminate();
                        resolve(e.data);
                    };
                });
                const shared = await new Promise((resolve) => {
                    const worker = new SharedWorker('/shared.js');
                    worker.port.start();
                    worker.port.onmessage = (e) => {
                        worker.port.close();
                        resolve(e.data);
                    };
                });
                await navigator.serviceWorker.register('/sw.js');
                await navigator.serviceWorker.ready;
                if (!navigator.serviceWorker.controller)
                    await new Promise((resolve) =>
                        navigator.serviceWorker.addEventListener(
                            'controllerchange',
                            resolve,
                            { once: true },
                        ),
                    );
                const service = await new Promise((resolve) => {
                    navigator.serviceWorker.addEventListener(
                        'message',
                        (e) => resolve(e.data),
                        { once: true },
                    );
                    navigator.serviceWorker.controller.postMessage('attempt');
                });
                return [dedicated, shared, service];
            });
            assert.deepEqual(completed, ['done', 'done', 'done']);
            const { attempts } = await (
                await fetch('http://lighthouse-proxy:4000/status')
            ).json();
            assert.equal(attempts, 0);
            // Even an allowed private target cannot reach the worker's anonymous listener through the proxy namespace.
            const response = await page.goto(localProxy + '/health');
            assert.notEqual(response.status(), 200);
            const management = await page.goto(
                `http://${tokens.controlAddress}:3129/scopes`,
            );
            assert.equal(management.status(), 403);
        } finally {
            await close();
        }
    });
}
void test('Chromium container cannot make a direct public TCP connection without its proxy', async () => {
    await new Promise((resolve, reject) => {
        const socket = net.connect({ host: '1.1.1.1', port: 443 });
        socket.setTimeout(3000);
        socket.once('connect', () => {
            socket.destroy();
            reject(new Error('Direct egress route exists'));
        });
        socket.once('timeout', () => {
            socket.destroy();
            resolve();
        });
        socket.once('error', (error) => {
            if (
                ['ENETUNREACH', 'EHOSTUNREACH', 'ETIMEDOUT'].includes(
                    error.code,
                )
            )
                resolve();
            else reject(error);
        });
    });
});
