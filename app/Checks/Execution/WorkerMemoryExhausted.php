<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Heptapod\Contracts\EngineFailure;
use RuntimeException;

/** The check worker hit its PHP memory_limit; RunCheck records this cause from its shutdown handler. */
final class WorkerMemoryExhausted extends RuntimeException implements EngineFailure {}
