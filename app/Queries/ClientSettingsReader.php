<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\ClientAvatarSiteData;
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
        $icons = $client->sites->filter(static fn (Site $site): bool => $site->icon_hash !== null)->sortBy('url')->map(static fn (Site $site): ClientAvatarSiteData => new ClientAvatarSiteData($site->id, $site->displayName(), is_string(parse_url($site->url, PHP_URL_HOST)) ? parse_url($site->url, PHP_URL_HOST) : $site->url))->values()->all();

        $timezones = \DateTimeZone::listIdentifiers();
        if (! in_array($client->timezone, $timezones, true)) {
            $timezones[] = $client->timezone;
        }

        $siteCounts = DB::table('sites')->where('client_id', $client->id);
        $siteCount = $siteCounts->count();
        $archivePausedCount = $siteCounts->whereNotNull('paused_by_archive_at')->count();

        return new ClientSettingsPageData(ClientData::fromClient($client), array_values($members), array_values($icons), $timezones, array_values(array_filter($this->sites->clientChoices($user), static fn (ClientData $choice): bool => $choice->id !== $client->id)), $siteCount, $archivePausedCount);
    }
}
