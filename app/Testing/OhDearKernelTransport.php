<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use OhDear\PhpSdk\OhDear;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

final class OhDearKernelTransport
{
    /** Replace only the SDK external HTTP seam; application routing and persistence stay real. */
    public function __invoke(string $token): OhDear
    {
        $sdk = new OhDear($token, 'http://localhost/api');
        $sdk->withMockClient(new MockClient([OhDear::class => function (PendingRequest $pending): MockResponse {
            app('auth')->forgetGuards();
            $kernel = app(Kernel::class);
            $wire = $pending->createPsrRequest();
            $uri = (string) $wire->getUri();
            $request = Request::create($uri, $wire->getMethod(), content: (string) $wire->getBody());
            $request->headers->replace($wire->getHeaders());
            $response = $kernel->handle($request);
            app(ApiSpec::class)->validate($wire->getMethod(), $uri, $response);
            $mock = MockResponse::make($response->getContent(), $response->getStatusCode(), array_map(fn (array $values): string => implode(', ', $values), $response->headers->all()));
            $kernel->terminate($request, $response);

            return $mock;
        }]));

        return $sdk;
    }
}
