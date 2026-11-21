<?php

declare(strict_types=1);

namespace Tests;

use Heptapod\Testing\ApiSpec;
use Heptapod\Testing\OhDearKernelTransport;
use Illuminate\Testing\TestResponse;
use OhDear\PhpSdk\OhDear;

abstract class ApiTestCase extends TestCase
{
    public function call(mixed $method, mixed $uri, mixed $parameters = [], mixed $cookies = [], mixed $files = [], mixed $server = [], mixed $content = null): TestResponse
    {
        $api = str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/api/');
        $webUser = null;
        if ($api) {
            $webUser = app('auth')->guard('web')->user();
            app('auth')->forgetGuards();
        }
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($webUser !== null) {
            // The browser session outlives the API request: the next web request is still signed in.
            app('auth')->guard('web')->setUser($webUser);
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
