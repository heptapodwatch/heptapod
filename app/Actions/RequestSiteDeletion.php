<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Jobs\PurgeDeletedSite;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a site from the UI or API: the site is paused as PauseSite pauses it and marked deletion pending
 * (Site::marked_for_deletion_at), so it shows "Deleting…", cannot be resumed and its open incidents end as a deletion.
 * PurgeDeletedSite then shrinks its history in the background and runs DeleteSite.
 */
final readonly class RequestSiteDeletion
{
    public function __construct(private PauseSite $pause) {}

    public function __invoke(Site $site): void
    {
        NotificationLocks::transaction(function () use ($site): void {
            // NotificationLocks: the checks, then the site, as the pause takes them, so the mark is set before it resolves.
            NotificationLocks::checks([], exclusive: DB::table('checks')->where('site_id', $site->id)->orderBy('id')->pluck('id'));
            NotificationLocks::sites([$site->id], exclusive: true);
            $locked = Site::query()->findOrFail($site->id);
            if ($locked->marked_for_deletion_at === null) {
                $locked->update(['marked_for_deletion_at' => now()]);
            }
            ($this->pause)($locked);
            PurgeDeletedSite::dispatch($locked->id)->afterCommit();
        });
    }
}
