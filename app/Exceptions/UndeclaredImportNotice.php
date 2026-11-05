<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

/** An import engine reason without a declared label; must reach the developer, never a row. */
final class UndeclaredImportNotice extends \LogicException {}
