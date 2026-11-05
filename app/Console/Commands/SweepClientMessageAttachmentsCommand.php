<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\SweepClientMessageAttachments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete client message attachment files that no message refers to')]
#[Signature('heptapod:client-messages:sweep')]
final class SweepClientMessageAttachmentsCommand extends Command
{
    public function handle(SweepClientMessageAttachments $sweep): int
    {
        $this->line('Deleted orphan client message attachments: '.$sweep());

        return self::SUCCESS;
    }
}
