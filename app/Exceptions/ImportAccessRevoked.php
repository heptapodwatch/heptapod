<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

/** The user who started an import lost the access an import needs while it ran; the run stops after the current site. */
final class ImportAccessRevoked extends \RuntimeException {}
