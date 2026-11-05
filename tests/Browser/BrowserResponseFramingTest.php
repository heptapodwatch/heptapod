<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BrowserResponseWire;

it('sends bodyless responses without illegal framing and leaves the next response clean', function (int $status): void {
    $wire = BrowserResponseWire::capture($status);
    [$headers, $body] = explode("\r\n\r\n", $wire['first'], 2);
    expect($headers)->toStartWith('HTTP/1.1 '.$status.' ')
        ->and(strtolower($headers))->not->toContain('transfer-encoding:')
        ->and(strtolower($headers))->not->toContain('content-length:')
        ->and($body)->toBe('')
        ->and($wire['next'])->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\nPROBE_OK")
        ->and($headers)->toContain('etag: "constant"');
})->with(['empty204' => 204, 'empty304' => 304]);

it('does not rewrite other statuses or nonempty responses at the browser boundary', function (int $status, string $body): void {
    $response = new Response($body, $status, [
        'Connection' => 'keep-alive', 'Content-Length' => (string) strlen($body),
        'Transfer-Encoding' => 'identity', 'X-Framing-Probe' => 'constant',
    ]);
    $headers = $response->headers->all();
    Event::dispatch(new RequestHandled(Request::create('/__browser-framing/other'), $response));
    expect($response->getStatusCode())->toBe($status)
        ->and($response->getContent())->toBe($body)
        ->and($response->headers->all())->toBe($headers);
})->with([
    'ordinary200' => [200, 'PROBE_OK'],
    'created201' => [201, 'PROBE_OK'],
    'redirect302' => [302, ''],
    'validation422' => [422, 'PROBE_OK'],
    'nonempty204' => [204, 'PROBE_OK'],
    'nonempty304' => [304, 'PROBE_OK'],
]);
