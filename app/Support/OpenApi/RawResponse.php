<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Response;

/** An OpenAPI response object written out by the app (error envelopes and explicit agent contracts). */
final class RawResponse extends Response
{
    /** @param array<string, mixed> $response */
    public function __construct(int $code, private readonly array $response)
    {
        parent::__construct($code);
    }

    #[\Override]
    public function serializeAs31(): mixed
    {
        return $this->response;
    }

    #[\Override]
    public function serializeAs32(): mixed
    {
        return $this->response;
    }
}
