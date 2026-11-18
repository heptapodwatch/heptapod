<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

/** Oh Dear answered with data the importer cannot read. The message is a lang key naming the endpoint, never source text. */
final class InvalidOhDearResponse extends \UnexpectedValueException {}
