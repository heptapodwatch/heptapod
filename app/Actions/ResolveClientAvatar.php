<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Data\Ui\ClientAvatarData;
use Heptapod\Enums\ClientAvatarSource;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Queries\GenericSiteIcons;
use Pdp\CannotProcessHost;
use Pdp\Domain;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ResolveClientAvatar
{
    public function __construct(private PublicSuffixList $suffixes, private GenericSiteIcons $genericIcons) {}

    public function __invoke(Client $client): ClientAvatarData
    {
        $logo = $client->getFirstMedia('logo');
        if ($logo instanceof Media) {
            $hash = $logo->getCustomProperty('avatar_hash');
            $backing = $logo->getCustomProperty('avatar_backing');
            if (! is_string($hash) || ! is_string($backing) || ! $logo->hasGeneratedConversion('avatar')) {
                throw new \LogicException('Client logo requires the avatar conversion and backing backfill.');
            }

            return new ClientAvatarData(route('clients.logo', ['client' => $client->id, 'hash' => $hash], false), SiteIconBacking::from($backing), ClientAvatarSource::Uploaded);
        }
        $chosen = $client->sites->first(static fn (Site $site): bool => $site->id === $client->avatar_site_id && $site->icon_hash !== null);
        $site = $chosen ?? $this->automaticSite($client);
        if ($site === null && $client->is_internal) {
            return new ClientAvatarData;
        }
        if ($site === null) {
            $hasGenericIcons = $client->sites->contains(fn (Site $site): bool => $this->genericIcons->contains($site->icon_hash));

            return new ClientAvatarData(source: $hasGenericIcons ? ClientAvatarSource::GenericInitials : ClientAvatarSource::Initials);
        }

        $host = parse_url($site->url, PHP_URL_HOST);
        if (! is_string($host)) {
            throw new \LogicException('Stored avatar source site must have a hostname.');
        }

        // An explicit site choice can use a generic icon; only automatic selection skips it.
        return new ClientAvatarData(route('sites.icon', ['site' => $site->id, 'hash' => $site->icon_hash], false), $site->icon_backing, $chosen === null ? ClientAvatarSource::Automatic : ClientAvatarSource::ChosenSite, $site->id, $host);
    }

    public function automaticSite(Client $client): ?Site
    {
        if ($client->is_internal) {
            return null;
        }
        if (! $client->sites->contains(fn (Site $site): bool => $site->icon_hash !== null && ! $this->genericIcons->contains($site->icon_hash))) {
            foreach ($client->sites as $site) {
                if (! is_string(parse_url($site->url, PHP_URL_HOST))) {
                    throw new \LogicException('Stored site URL must have a hostname.');
                }
            }

            return null;
        }
        /** @var array<string, array{count: int, oldest: Site, candidates: list<array{site: Site, apex: bool}>}> $domains */
        $domains = [];
        foreach ($client->sites->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $site) {
            $host = parse_url($site->url, PHP_URL_HOST);
            if (! is_string($host)) {
                throw new \LogicException('Stored site URL must have a hostname.');
            }
            $host = strtolower(trim($host, '[]'));
            // IPs and local/unknown suffixes have no registrable domain; group by exact host.
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $domain = $host;
            } else {
                try {
                    $domain = $this->suffixes->rules()->resolve(Domain::fromIDNA2008($host))->registrableDomain()->toString();
                } catch (CannotProcessHost) {
                    // Laravel accepts some hosts that strict IDNA rejects; they cannot identify an automatic domain.
                    continue;
                }
            }
            if ($domain === '') {
                $domain = $host;
            }
            $domains[$domain] ??= ['count' => 0, 'oldest' => $site, 'candidates' => []];
            $domains[$domain]['count']++;
            if ($site->icon_hash !== null && ! $this->genericIcons->contains($site->icon_hash)) {
                $domains[$domain]['candidates'][] = ['site' => $site, 'apex' => $host === $domain || $host === 'www.'.$domain];
            }
        }
        $domains = array_filter($domains, static fn (array $domain): bool => $domain['candidates'] !== []);
        uasort($domains, static function (array $a, array $b): int {
            $count = $b['count'] <=> $a['count'];
            if ($count !== 0) {
                return $count;
            }
            $age = $a['oldest']->created_at <=> $b['oldest']->created_at;

            return $age !== 0 ? $age : $a['oldest']->id <=> $b['oldest']->id;
        });
        $winner = reset($domains);
        if ($winner === false) {
            return null;
        }
        // Candidate order is already oldest-first. Prefer apex/www without disturbing ties.
        foreach ($winner['candidates'] as $candidate) {
            if ($candidate['apex']) {
                return $candidate['site'];
            }
        }

        return $winner['candidates'][0]['site'];
    }
}
