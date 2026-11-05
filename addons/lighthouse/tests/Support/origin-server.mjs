import http from 'node:http';
import { createHash } from 'node:crypto';
const host = process.argv[2] ?? 'heptapod-app';
let address;
const record = (label, request) => {
    const header = request.headers['x-lighthouse-probe'];
    process.stdout.write(
        `${label}:${header === undefined ? 'absent' : header === 'origin-only-probe' ? 'expected' : 'unexpected'}\n`,
    );
};
const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
const privateServer = http.createServer((request, response) => {
    record(`private-${request.url.slice(1)}`, request);
    response.writeHead(200, {
        'content-type':
            request.url === '/iframe' ? 'text/html' : 'image/svg+xml',
    });
    response.end(
        request.url === '/iframe'
            ? '<!doctype html><title>Private frame</title>'
            : svg,
    );
});
privateServer.on('upgrade', (request, socket) => {
    record('private-socket', request);
    const accept = createHash('sha1')
        .update(
            request.headers['sec-websocket-key'] +
                '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
        )
        .digest('base64');
    socket.end(
        `HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`,
    );
});
privateServer.on('connection', () => process.stdout.write('private-connect\n'));
const origin = http.createServer((request, response) => {
    address = request.socket.localAddress;
    if (!address) throw new Error('Fixture requires a local address');
    if (request.url === '/redirect' || request.url === '/private-redirect') {
        record('origin', request);
        response.writeHead(302, {
            location:
                request.url === '/private-redirect'
                    ? `http://${address}:${privateServer.address().port}/redirect`
                    : `http://${host}:${external.address().port}/page`,
        });
        return response.end();
    }
    record('same-origin-asset', request);
    response.writeHead(200, { 'content-type': 'image/svg+xml' });
    response.end(svg);
});
const external = http.createServer((request, response) => {
    if (request.url === '/page') {
        record('redirect', request);
        const privateOrigin = `http://${address}:${privateServer.address().port}`;
        response.writeHead(200, { 'content-type': 'text/html' });
        return response.end(
            `<!doctype html><html lang="en"><head><title>Origin isolation</title><meta name="viewport" content="width=device-width"><meta name="description" content="Network isolation fixture"><link rel="preconnect" href="${privateOrigin}"><link rel="prefetch" href="${privateOrigin}/prefetch"></head><body><h1>Origin isolation</h1><img alt="same origin" src="http://${host}:${origin.address().port}/same.svg"><img alt="other port" src="/external.svg"><img alt="private" src="${privateOrigin}/asset"><iframe title="private" src="${privateOrigin}/iframe"></iframe><script>globalThis.fixtureSocket = new WebSocket('ws://${address}:${privateServer.address().port}/socket'); fixtureSocket.onerror = () => {}; fetch('http://127.0.0.1:3128/health').catch(() => {}); fetch('http://lighthouse-proxy:3128/scopes').catch(() => {}); fetch('/script-ran'); if (typeof RTCPeerConnection !== 'undefined') fetch('/rtc-enabled');</script></body></html>`,
        );
    }
    record(
        request.url === '/external.svg'
            ? 'other-port-asset'
            : request.url.slice(1),
        request,
    );
    response.writeHead(200, { 'content-type': 'image/svg+xml' });
    response.end(svg);
});
await new Promise((resolve) => privateServer.listen(0, '0.0.0.0', resolve));
await new Promise((resolve) => origin.listen(0, '0.0.0.0', resolve));
await new Promise((resolve) => external.listen(0, '0.0.0.0', resolve));
process.stdout.write(`${origin.address().port}\n`);
