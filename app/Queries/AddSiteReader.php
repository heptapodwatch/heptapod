<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Checks\SiteProbe;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Data\Ui\AddSitePageData;
use Heptapod\Data\Ui\CheckOptionData;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;

final readonly class AddSiteReader
{
    public function __construct(private SitesReader $sites, private CheckRegistry $registry, private SitePolicy $policy, private SiteProbe $probe) {}

    public function read(User $user, ?int $selectedClientId = null): AddSitePageData
    {
        $choices = $this->sites->scopedClients($user);
        $choices->getQuery()->orderByDesc('is_internal')->orderBy('id');
        /** @var list<int> $firstChoices */
        $firstChoices = $choices->getQuery()->limit(2)->pluck('clients.id')->all();
        if ($selectedClientId !== null) {
            $selected = $this->sites->scopedClients($user);
            abort_unless($selected->getQuery()->where('clients.id', $selectedClientId)->exists(), 404);
        }

        $contextClientId = $selectedClientId ?? ($firstChoices[0] ?? null);
        // Skip the redundant membership lookup for read-only users; every true projection still requires the write policy.
        $canCreate = $contextClientId !== null && $user->hasAnyRole(['Owner', 'Admin']) && $this->policy->create($user, $contextClientId)->allowed();

        $recommended = $this->probe->recommendedChecks('https://');

        return new AddSitePageData(array_map(fn (CheckKey $key): CheckOptionData => CheckOptionData::fromType($this->registry->get($key), in_array($key, $recommended, true)), $this->registry->keys()), $canCreate, $selectedClientId, \DateTimeZone::listIdentifiers(),
            count($firstChoices) === 1 ? $firstChoices[0] : null);
    }
}
