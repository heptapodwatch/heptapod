import http from 'node:http';
import { once } from 'node:events';
import { createGzip } from 'node:zlib';

const mode = process.argv[2];
const releaseTail = once(process.stdin, 'data').then(([input]) => {
    if (input.toString() !== 'tail\n')
        throw new Error('Unexpected tail release');
});
const server = http.createServer(async (request, response) => {
    request.resume();
    if (request.method === 'DELETE') {
        response.writeHead(204);
        response.end();
        return;
    }
    if (request.url === '/scopes') {
        response.end(JSON.stringify({ token: 'a'.repeat(64) }));
        return;
    }
    response.writeHead(mode === 'error' ? 503 : 200, {
        'Content-Type': 'application/json',
        ...(mode === 'gzip' ? { 'Content-Encoding': 'gzip' } : {}),
    });
    const output = mode === 'gzip' ? createGzip() : response;
    if (output !== response) output.pipe(response);
    output.write('{"finalUrl":"https://example.com/","padding":"');
    const chunk = 'x'.repeat(64 * 1024);
    for (let index = 0; index < 129; index++) {
        if (response.destroyed) break;
        if (!output.write(chunk)) {
            await new Promise((resolve) => {
                const ready = () => {
                    output.off('drain', ready);
                    response.off('close', ready);
                    resolve();
                };
                output.once('drain', ready);
                response.once('close', ready);
            });
        }
    }
    if (mode === 'gzip') output.flush();
    await releaseTail;
    process.stdout.write('tail sent\n');
    output.end('"}');
});
server.listen(0, '127.0.0.1', () => {
    process.stdout.write(`${server.address().port}\n`);
});
