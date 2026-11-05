<?php

declare(strict_types=1);

namespace Heptapod\Import;

use RuntimeException;

/** Only operator-safe messages belong here; SQL diagnostics stay in private run files. */
final class ImportFailure extends RuntimeException {}
