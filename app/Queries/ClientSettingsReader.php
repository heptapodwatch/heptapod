<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientMemberData;
use Heptapod\Data\Ui\ClientSettingsPageData;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ClientSettingsReader
{
    public function __construct(private SitesReader $sites) {}

    public function read(Client $client, User $user): ClientSettingsPageData
    {
        $users = User::query()->with('roles');
        $users->where(function (Builder $query) use ($client): void {
            $query->whereHas('roles', static function (Builder $roles): void {
                $roles->getQuery()->whereIn('name', ['Owner', 'Admin']);
            })->orWhereHas('clients', static function (Builder $clients) use ($client): void {
                $clients->getQuery()->where('clients.id', $client->id);
            });
        });
        $users->getQuery()->orderBy('users.name');
        $members = $users->get()->map(static function (User $user): ClientMemberData {
            $role = $user->getRoleNames()->first();
            if (! is_string($role)) {
                throw new \LogicException('Client member must have a role.');
            }

            return new ClientMemberData($user->name, $role);
        })->all();

        $client->load(Client::AvatarRelations);
        $hasIconSites = $client->sites->contains(static fn (Site $site): bool => $site->icon_hash !== null);

        $timezones = \DateTimeZone::listIdentifiers();
        if ($client->timezone !== null && ! in_array($client->timezone, $timezones, true)) {
            $timezones[] = $client->timezone;
        }

        $siteCounts = DB::table('sites')->where('client_id', $client->id);
        $siteCount = $siteCounts->count();
        $archivePausedCount = $siteCounts->whereNotNull('paused_by_archive_at')->count();

        $internal = $this->sites->scopedClients($user)->getQuery()->where('clients.is_internal', true)->where('clients.id', '!=', $client->id)->value('clients.id');

        return new ClientSettingsPageData(ClientData::fromClient($client), array_values($members), $hasIconSites, $timezones, is_int($internal) ? $internal : null, $siteCount, $archivePausedCount);
    }
}
