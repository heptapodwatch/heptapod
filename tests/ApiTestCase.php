<?php

declare(strict_types=1);

namespace Tests;

use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\OhDearKernelTransport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use OhDear\PhpSdk\OhDear;

abstract class ApiTestCase extends TestCase
{
    public function call(mixed $method, mixed $uri, mixed $parameters = [], mixed $cookies = [], mixed $files = [], mixed $server = [], mixed $content = null): TestResponse
    {
        $api = str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/api/');
        if ($api) {
            app('auth')->forgetGuards();
        }
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($api && $this->actingWebUser instanceof Authenticatable) {
            // actingAs() outlives the API request. A real sign-in needs nothing: the next web request reads its session.
            app('auth')->guard('web')->setUser($this->actingWebUser);
        }
        if (str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/api/') && ! str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/api/internal/')) {
            app(ApiSpec::class)->validate($method, $uri, $response->baseResponse);
        }

        return $response;
    }

    protected function sdk(string $token): OhDear
    {
        return app(OhDearKernelTransport::class)($token);
    }
}
