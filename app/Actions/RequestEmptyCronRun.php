<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;

final readonly class RequestEmptyCronRun
{
    public function __construct(private CronTaskStore $store, private RequestCheckRun $request) {}

    /** Called while holding the parent check lock. Busy or paused checks evaluate on their next normal run. */
    public function __invoke(Check $check): void
    {
        $check->refresh();
        $site = $check->site()->getQuery()->firstOrFail();
        if ($check->enabled && $check->claim_token === null && $site->paused_at === null && $site->marked_for_deletion_at === null && $this->store->list($check->id) === []) {
            ($this->request)($check, RunOrigin::System);
        }
    }
}
