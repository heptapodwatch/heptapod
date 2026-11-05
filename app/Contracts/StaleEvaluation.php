<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** A changed input invalidates an observation; it is not a checker failure. */
final class StaleEvaluation extends \RuntimeException {}
