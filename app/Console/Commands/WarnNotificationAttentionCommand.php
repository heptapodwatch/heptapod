<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Models\NotificationDestination;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Description('Warn about notification channels that need credential renewal')]
#[Signature('heptapod:notifications:attention')]
final class WarnNotificationAttentionCommand extends Command
{
    public function handle(): int
    {
        $ids = NotificationDestination::query()->where('needs_attention', true)->pluck('id')->all();
        if ($ids !== []) {
            Log::warning(__('notifications.needs_attention_warning'), ['destination_ids' => $ids]);
            $this->warn(__('notifications.needs_attention_warning'));
        }

        return self::SUCCESS;
    }
}
