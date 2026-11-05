import http from 'node:http';
let attempts = 0;
const peer = http.createServer((request, response) => {
    attempts++;
    response.writeHead(200, { 'access-control-allow-origin': '*' });
    response.end('peer');
});
peer.listen(4001, '127.0.0.1');
const attempt = "fetch('http://127.0.0.1:4001/peer').catch(() => {})";
http.createServer((request, response) => {
    if (request.url === '/status') {
        response.end(JSON.stringify({ attempts }));
        return;
    }
    if (request.url === '/reset') {
        attempts = 0;
        response.end('reset');
        return;
    }
    if (request.url === '/dedicated.js') {
        response.setHeader('content-type', 'text/javascript');
        response.end(`${attempt}.then(() => postMessage('done'));`);
        return;
    }
    if (request.url === '/shared.js') {
        response.setHeader('content-type', 'text/javascript');
        response.end(
            `onconnect=e=>{const port=e.ports[0];${attempt}.then(()=>port.postMessage('done'));};`,
        );
        return;
    }
    if (request.url === '/sw.js') {
        response.setHeader('content-type', 'text/javascript');
        response.end(
            `oninstall=()=>skipWaiting();onactivate=e=>e.waitUntil(clients.claim());onmessage=e=>${attempt}.then(()=>e.source.postMessage('done'));`,
        );
        return;
    }
    response.setHeader('content-type', 'text/html');
    response.end('<!doctype html><title>Worker network fixture</title>');
}).listen(4000, '0.0.0.0');
