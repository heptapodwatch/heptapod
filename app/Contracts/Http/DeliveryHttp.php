<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Guarded notification transport. Each handler owns one delivery attempt's DNS scope and deadline. */
interface DeliveryHttp
{
    /**
     * Guzzle base handler for PendingRequest::setHandler() or a Guzzle client.
     * Refusals reject with FetchException; oversized responses with FetchException(TooLarge).
     */
    public function handler(string $destinationUrl, float $timeoutSeconds = 20.0, int $maxResponseBytes = 1048576): callable;
}
