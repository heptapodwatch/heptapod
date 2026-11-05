<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Support\Api\Idempotency;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete expired API idempotency records')]
#[Signature('heptapod:prune-api-idempotency')]
final class PruneApiIdempotencyCommand extends Command
{
    public function handle(Idempotency $idempotency): int
    {
        $this->line('Deleted expired idempotency records: '.$idempotency->pruneExpired());

        return self::SUCCESS;
    }
}
