<?php

declare(strict_types=1);

namespace Heptapod\Contracts\OhDear;

/** An Oh Dear id in the source already belongs to another local record; the importer skips that site and keeps going. */
final class OhDearIdentityConflict extends \RuntimeException {}
