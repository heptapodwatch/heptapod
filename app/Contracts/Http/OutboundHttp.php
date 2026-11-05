<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

use Heptapod\Contracts\CheckContext;

interface OutboundHttp
{
    public function fetch(FetchRequest $request, CheckContext $context): FetchResult;

    /**
     * Guzzle library integration. Keep the handler intact; it enforces policy even
     * when request options override client defaults. Transfer failures throw only
     * FetchException, never vendor exceptions containing request/response secrets.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(FetchRequest $request, CheckContext $context, ?float $selectTimeoutSeconds = null): array;

    /** Per-execution sitemap pacing: host cap four, 110 ms plus the configured extra delay. */
    public function pacedClient(CheckContext $context, int $additionalDelayMilliseconds = 0): PacedHttpClient;
}
