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
        if ($check->claim_token === null && $check->isActive() && $this->store->list($check->id) === []) {
            ($this->request)($check, RunOrigin::System);
        }
    }
}
