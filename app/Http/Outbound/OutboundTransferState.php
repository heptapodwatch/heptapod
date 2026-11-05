<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

final class OutboundTransferState
{
    public ?int $status = null;

    public ?int $errno = null;

    public ?string $tlsTarget = null;

    public int $decoded = 0;

    public bool $overflow = false;

    public ?\Throwable $callbackError = null;
}
