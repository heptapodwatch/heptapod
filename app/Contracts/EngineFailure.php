<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * Marks an exception whose message Heptapod writes. Every message such an exception can carry must be in the
 * EngineMessage catalog; scripts/check-engine-messages.php enforces this for every implementing class.
 */
interface EngineFailure {}
