import http from 'node:http';

// Forward to the real single-audit service; retain only response status, never payloads or tokens.
const server = http.createServer((request, response) => {
    const upstream = http.request(
        'http://lighthouse:3000/lighthouse',
        {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
        },
        (reply) => {
            process.stdout.write(`audit-status:${reply.statusCode}\n`);
            response.writeHead(reply.statusCode, {
                'content-type': 'application/json',
            });
            reply.pipe(response);
        },
    );
    upstream.on('error', (error) => {
        throw error;
    });
    request.pipe(upstream);
});
server.listen(0, '127.0.0.1', () =>
    process.stdout.write(`${server.address().port}\n`),
);
process.on('SIGTERM', () => server.close());
