<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Local filesystem boundary. An unavailable or unreadable path must throw, never report healthy space. */
interface DiskSpaceReader
{
    /** @return array{free: int, total: int} bytes available to the app and total filesystem bytes */
    public function read(string $path): array;
}
