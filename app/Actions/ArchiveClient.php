<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ArchiveClient
{
    public function __construct(private PauseSite $pause, private MoveSiteToClient $move) {}

    public function __invoke(Client $client, ?User $user = null, ?int $targetClientId = null): void
    {
        if ($targetClientId !== null) {
            $this->moveSitesAndArchive($client, $user ?? throw new \LogicException('Moving sites requires an actor.'), $targetClientId);

            return;
        }
        NotificationLocks::transaction(function () use ($client): void {
            NotificationLocks::clients([$client->id]);
            $locked = Client::query()->findOrFail($client->id);
            if ($locked->is_internal) {
                throw new \LogicException('Internal client cannot be archived.');
            }
            if ($locked->archived_at !== null) {
                return;
            }
            // NotificationLocks: all checks, all sites, then incidents and deliveries, before the first pause.
            /** @var list<int> $siteIds */
            $siteIds = DB::table('sites')->where('client_id', $locked->id)->orderBy('id')->pluck('id')->all();
            /** @var list<int> $checkIds */
            $checkIds = DB::table('checks')->whereIn('site_id', $siteIds)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: $checkIds);
            NotificationLocks::sites($siteIds, exclusive: true);
            ResolveInactiveCheckIncidents::lockWork($checkIds);
            $query = Site::query();
            $query->getQuery()->whereIn('id', $siteIds)->orderBy('id');
            foreach ($query->get() as $site) {
                if ($site->paused_at === null) {
                    $site->update(['paused_by_archive_at' => now()]);
                }
                ($this->pause)($site);
            }
            $locked->update(['archived_at' => now()]);
        });
    }

    private function moveSitesAndArchive(Client $client, User $user, int $targetClientId): void
    {
        $client = $client->fresh() ?? throw new \LogicException('The source client must exist.');
        if ($client->is_internal) {
            throw new \LogicException('Internal client cannot be archived.');
        }
        if ($client->archived_at !== null) {
            return;
        }
        $sites = Site::query();
        $sites->getQuery()->where('client_id', $client->id)->orderBy('id');
        // Release each site's history locks before moving the next; retries continue from the remaining sites.
        $moved = 0;
        foreach ($sites->get() as $site) {
            try {
                ($this->move)($user, $site, $targetClientId);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['client_id' => trans_choice('archive_client.move_failed', $moved, [
                    'site' => $site->displayName(), 'count' => $moved,
                ]).' '.$exception->validator->errors()->first('client_id')]);
            }
            $moved++;
        }
        NotificationLocks::transaction(function () use ($client): void {
            NotificationLocks::clients([$client->id]);
            $locked = Client::query()->findOrFail($client->id);
            if (DB::table('sites')->where('client_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['client_id' => __('Sites were added while archiving; try again.')]);
            }
            $locked->update(['archived_at' => now()]);
        });
    }
}
