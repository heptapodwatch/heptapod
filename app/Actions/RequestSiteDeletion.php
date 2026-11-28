<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Jobs\PurgeDeletedSite;
use Heptapod\Models\Site;

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
            ($this->pause)($site, forDeletion: true);
            PurgeDeletedSite::dispatch($site->id)->afterCommit();
        });
    }
}
