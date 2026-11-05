<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use RuntimeException;

/** A filesystem read failure with a typed reason; the native message stays in logs, never in UI copy. */
final class DiskReadException extends RuntimeException
{
    public function __construct(public readonly DiskReadFailure $reason, string $path, ?string $detail = null)
    {
        parent::__construct($reason->value.' '.$path.($detail === null ? '' : ': '.$detail));
    }
}
