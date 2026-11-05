<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use RuntimeException;

/** Boundary implementations wrap transport failures with the original exception as previous. */
class ExternalNetworkException extends RuntimeException implements EngineFailure {}
