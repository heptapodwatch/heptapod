<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class RestoreClient
{
    public function __construct(private ResumeSite $resume, private AuthorizeSiteWrite $authorize) {}

    /** With an $actor the restore is authorized again under the lock, with the actor as the account is now. */
    public function __invoke(Client $client, bool $resumeSites = true, ?User $actor = null): void
    {
        NotificationLocks::transaction(function () use ($client, $resumeSites, $actor): void {
            // NotificationLocks level 0: a change of who may write what holds the access-structure lock exclusive, first.
            NotificationLocks::accessExclusive();
            NotificationLocks::clients([$client->id]);
            $locked = Client::query()->findOrFail($client->id);
            if ($actor instanceof User) {
                Gate::forUser($this->authorize->currentActor($actor))->authorize('restore', $locked);
            }
            if ($locked->archived_at === null) {
                return;
            }
            $siteIds = DB::table('sites')->where('client_id', $locked->id)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: DB::table('checks')->whereIn('site_id', $siteIds)->orderBy('id')->pluck('id'));
            NotificationLocks::sites($siteIds, exclusive: true);
            $locked->update(['archived_at' => null]);
            $sites = Site::query()->whereKey($siteIds);
            $sites->getQuery()->whereNotNull('paused_by_archive_at')->orderBy('id');
            foreach ($sites->get() as $site) {
                if ($resumeSites) {
                    ($this->resume)($site);
                }
                $site->update(['paused_by_archive_at' => null]);
            }
        });
    }
}
