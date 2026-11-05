<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Http\FetchFailure;

/** Separates a browser's target navigation failure from a Lighthouse service failure. */
final class TargetAvailabilityException extends ExternalNetworkException
{
    public function __construct(public readonly FetchFailure $failure)
    {
        parent::__construct($failure->detail());
    }
}
