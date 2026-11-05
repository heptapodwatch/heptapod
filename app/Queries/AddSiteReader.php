<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Checks\SiteProbe;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Data\Ui\AddSitePageData;
use Heptapod\Data\Ui\CheckOptionData;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;

final readonly class AddSiteReader
{
    public function __construct(private SitesReader $sites, private CheckRegistry $registry, private SitePolicy $policy, private SiteProbe $probe) {}

    public function read(User $user, ?int $selectedClientId = null): AddSitePageData
    {
        $clients = $this->sites->clientChoices($user);
        if ($selectedClientId !== null && ! in_array($selectedClientId, array_map(static fn (ClientData $client): int => $client->id, $clients), true)) {
            abort(404);
        }

        $contextClientId = $selectedClientId ?? ($clients[0]->id ?? null);
        // Skip the redundant membership lookup for read-only users; every true projection still requires the write policy.
        $canCreate = $contextClientId !== null && $user->hasAnyRole(['Owner', 'Admin']) && $this->policy->create($user, $contextClientId)->allowed();

        $recommended = $this->probe->recommendedChecks('https://');

        return new AddSitePageData($clients, array_map(fn (CheckKey $key): CheckOptionData => CheckOptionData::fromType($this->registry->get($key), in_array($key, $recommended, true)), $this->registry->keys()), $canCreate, $selectedClientId, \DateTimeZone::listIdentifiers());
    }
}
