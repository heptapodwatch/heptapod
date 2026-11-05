<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\MonitorCountProvider;
use Heptapod\Contracts\MonitorCounts;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;

final readonly class MonitorCountsReader
{
    public function __construct(private SitePolicy $policy) {}

    public function read(User $user, int $days = 30): MonitorCounts
    {
        $ids = $this->policy->visibleSiteIds($user);
        $now = CarbonImmutable::now('UTC');
        $until = $now->addDays($days);
        $certificates = $domains = $tasks = $overdue = 0;
        foreach (app()->tagged('monitor-counts') as $provider) {
            if (! $provider instanceof MonitorCountProvider) {
                throw new \LogicException('Monitor count provider must implement MonitorCountProvider.');
            }
            $counts = $provider->counts($ids, $now, $until);
            $certificates += $counts->certificates;
            $domains += $counts->domains;
            $tasks += $counts->scheduledTasks;
            $overdue += $counts->overduePings;
        }

        return new MonitorCounts($certificates, $domains, $tasks, $overdue);
    }
}
