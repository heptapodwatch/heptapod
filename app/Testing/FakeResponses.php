<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\Http\FetchException;
use LogicException;

/** @template T of object */
final class FakeResponses
{
    /** @var array<string, T|ExternalNetworkException|FetchException> */
    private array $responses = [];

    /** @param class-string<T> $responseType */
    public function __construct(private readonly string $responseType) {}

    /**
     * @param  list<mixed>  $arguments
     * @param  T|ExternalNetworkException|FetchException  $response
     */
    public function add(string $operation, array $arguments, object $response): void
    {
        $this->responses[$this->key($operation, $arguments)] = $response;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return T
     */
    public function get(string $operation, array $arguments): object
    {
        $key = $this->key($operation, $arguments);
        if (! array_key_exists($key, $this->responses)) {
            // Never expose headers/credentials from arguments in failure diagnostics.
            throw new LogicException("No configured {$this->responseType} response for {$operation}.");
        }
        $response = $this->responses[$key];
        if ($response instanceof ExternalNetworkException || $response instanceof FetchException) {
            throw $response;
        }

        return $response;
    }

    /** @param list<mixed> $arguments */
    private function key(string $operation, array $arguments): string
    {
        return $operation.':'.hash('sha256', serialize($arguments));
    }
}
