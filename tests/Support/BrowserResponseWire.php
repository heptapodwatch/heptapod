<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Execution;
use Pest\Browser\ServerManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class BrowserResponseWire
{
    /** @return array{first: string, next: string, reused: bool} */
    public static function capture(int $status): array
    {
        Route::get('/__browser-framing/empty', fn (): Response => new Response('', $status, [
            'X-Framing-Probe' => 'constant', 'ETag' => '"constant"', 'Connection' => 'keep-alive',
        ]));
        Route::get('/__browser-framing/known', fn (): Response => new Response('PROBE_OK', 200, [
            'Content-Length' => '8', 'Connection' => 'close',
        ]));
        $server = ServerManager::instance()->http();
        $server->bootstrap();
        $origin = parse_url($server->rewrite('/'));
        $address = 'tcp://'.$origin['host'].':'.$origin['port'];
        $socket = self::connect($address);
        try {
            self::send($socket, '/__browser-framing/empty', 'keep-alive');
            $wire = '';
            $deadline = microtime(true) + 5;
            while (! str_contains($wire, "\r\n\r\n")) {
                $wire = self::read($socket, $wire, $deadline);
                if (feof($socket) && ! str_contains($wire, "\r\n\r\n")) {
                    throw new RuntimeException('EOF before response headers');
                }
            }
            $headerEnd = strpos($wire, "\r\n\r\n") + 4;
            $reused = ! str_contains(strtolower(substr($wire, 0, $headerEnd)), "connection: close\r\n");
            if ($reused) {
                self::send($socket, '/__browser-framing/known', 'close');
                $wire = self::finish($socket, $wire);
                $nextStart = strpos($wire, "HTTP/1.1 200 OK\r\n", $headerEnd);
                if ($nextStart === false) {
                    throw new RuntimeException('Known response missing from reused connection');
                }
                $first = substr($wire, 0, $nextStart);
                $next = substr($wire, $nextStart);
            } else {
                $first = self::finish($socket, $wire);
                fclose($socket);
                $socket = self::connect($address);
                self::send($socket, '/__browser-framing/known', 'close');
                $next = self::finish($socket, '');
            }
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        File::ensureDirectoryExists(storage_path('framework/testing'));
        file_put_contents(storage_path('framework/testing/browser-framing-'.$status.'-first.wire'), $first);
        file_put_contents(storage_path('framework/testing/browser-framing-'.$status.'-next.wire'), $next);

        return ['first' => $first, 'next' => $next, 'reused' => $reused];
    }

    /** @return resource */
    private static function connect(string $address): mixed
    {
        $socket = stream_socket_client($address, timeout: 5);
        if ($socket === false) {
            throw new RuntimeException('Synthetic wire connection failed');
        }
        stream_set_blocking($socket, false);

        return $socket;
    }

    /** @param resource $socket */
    private static function send(mixed $socket, string $path, string $connection): void
    {
        $request = "GET $path HTTP/1.1\r\nHost: synthetic.test\r\nConnection: $connection\r\n\r\n";
        if (fwrite($socket, $request) !== strlen($request)) {
            throw new RuntimeException('Synthetic request write incomplete');
        }
    }

    /** @param resource $socket */
    private static function read(mixed $socket, string $wire, float $deadline): string
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Synthetic response did not complete');
        }
        // Pest's in-process server needs its event loop driven while the raw client reads.
        Execution::instance()->tick();
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            throw new RuntimeException('Synthetic response read failed');
        }

        return $wire.$chunk;
    }

    /** @param resource $socket */
    private static function finish(mixed $socket, string $wire): string
    {
        $deadline = microtime(true) + 5;
        while (! feof($socket)) {
            $wire = self::read($socket, $wire, $deadline);
        }

        return $wire;
    }
}
