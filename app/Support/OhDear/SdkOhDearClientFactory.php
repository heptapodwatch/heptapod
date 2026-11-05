<?php

declare(strict_types=1);

namespace Heptapod\Support\OhDear;

use Heptapod\Contracts\OhDear\OhDearClientFactory;
use OhDear\PhpSdk\OhDear;

final readonly class SdkOhDearClientFactory implements OhDearClientFactory
{
    public function make(#[\SensitiveParameter] string $token, bool $dryRun): OhDear
    {
        return new OhDear($token);
    }
}
