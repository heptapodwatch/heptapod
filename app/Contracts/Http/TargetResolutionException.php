<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

use Heptapod\Contracts\EngineFailure;

final class TargetResolutionException extends \RuntimeException implements EngineFailure {}
