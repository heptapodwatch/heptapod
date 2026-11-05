<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Data\Ui\IconRefreshResultData;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RequestSiteIconRefresh
{
    public function __invoke(Site $site): void
    {
        DB::transaction(function () use ($site): void {
            $query = Site::query();
            NotificationLocks::forUpdate($query->getQuery());
            $current = $query->findOrFail($site->id);
            if (! $current->iconRefreshEligible()) {
                throw ValidationException::withMessages(['icon' => __('icons.unavailable')]);
            }
            if ($current->icon_manual_requested_at?->greaterThan(now()->subMinute()) === true) {
                throw ValidationException::withMessages(['icon' => __('icons.throttled')]);
            }
            $current->forceFill(['icon_manual_requested_at' => now(), 'icon_manual_result' => new IconRefreshResultData('icons.queued')])->save();
            RefreshSiteIcon::dispatch($site->id, true)->afterCommit();
        });
    }
}
