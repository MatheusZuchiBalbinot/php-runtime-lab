/**
 * Dependency stub for the external-io benchmark route.
 *
 * Stands in for the thing a real request waits on — a database, an internal
 * API — with a latency you control. Written in Node rather than PHP on
 * purpose: it must never be the bottleneck, and it must not be mistaken for
 * one of the runtimes under test. Node handles thousands of parked
 * connections on an event loop, so the wait it imposes is the wait it was
 * asked for.
 *
 * The delay defaults to the same value as the blocking-wait route, which
 * makes the two a controlled pair: identical wait, one simulated with
 * usleep() and one incurred over a real socket. The difference between them
 * is the cost of real I/O and of whether the runtime's driver yields.
 */

const http = require('http');

const PORT = Number(process.env.STUB_PORT || 8080);
const DELAY_MS = Number(process.env.STUB_DELAY_MS || 10);

const server = http.createServer((request, response) => {
    setTimeout(() => {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ status: 'ok', delay_ms: DELAY_MS }));
    }, DELAY_MS);
});

// Without this Node closes idle sockets after 5s, which would make the
// runtimes reconnect mid-benchmark and measure connection setup instead of
// the dependency call.
server.keepAliveTimeout = 120000;
server.headersTimeout = 125000;

server.listen(PORT, '0.0.0.0', () => {
    process.stderr.write(`stub listening on ${PORT}, delay ${DELAY_MS}ms\n`);
});
