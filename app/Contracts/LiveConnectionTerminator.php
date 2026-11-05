<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface LiveConnectionTerminator
{
    public function terminate(int $userId): void;
}
