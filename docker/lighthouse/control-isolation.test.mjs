import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import net from 'node:net';
import { existsSync } from 'node:fs';

let input = '';
for await (const chunk of process.stdin) input += chunk;
const { controlAddress } = JSON.parse(input);
void test('Chromium container cannot access scope management or its former control socket', async () => {
    assert.equal(existsSync('/service/proxy-control/control.sock'), false);
    await new Promise((resolve, reject) => {
        const socket = net.connect({ host: controlAddress, port: 3129 });
        socket.setTimeout(3000);
        socket.once('connect', () => {
            socket.destroy();
            reject(new Error('Chromium can create arbitrary scopes'));
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
