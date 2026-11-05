<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

use Heptapod\Contracts\EngineFailure;

final class FetchException extends \RuntimeException implements EngineFailure
{
    public function __construct(public readonly FetchFailure $failure)
    {
        parent::__construct($failure->detail());
    }
}
