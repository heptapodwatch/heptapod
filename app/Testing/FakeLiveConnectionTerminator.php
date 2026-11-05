<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\LiveConnectionTerminator;

final class FakeLiveConnectionTerminator implements LiveConnectionTerminator
{
    /** @var list<int> */
    public array $terminated = [];

    public function terminate(int $userId): void
    {
        $this->terminated[] = $userId;
    }
}
