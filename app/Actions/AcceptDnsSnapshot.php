<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\CheckPending;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DnsBaselineAcceptance;
use Heptapod\Enums\CheckPendingReason;
use Heptapod\Events\LiveSignal;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AcceptDnsSnapshot
{
    public function __construct(private CheckRegistry $registry, private RequestCheckRun $run) {}

    public function __invoke(User $actor, Check $check, int $expectedRunId): CheckRunAttempt
    {
        return DB::transaction(function () use ($actor, $check, $expectedRunId): CheckRunAttempt {
            $type = $this->registry->get($check->type);
            if (! $type instanceof DnsBaselineAcceptance) {
                throw new \LogicException('DNS check must provide baseline acceptance.');
            }
            $runId = $type->accept($check->id, $actor->id, $actor->name, $expectedRunId);
            $attempt = ($this->run)($check, RunOrigin::System);
            // After the claim holds the check row lock; a refused claim throws first and rolls the accept back.
            CheckPending::mark($check, CheckPendingReason::DnsAcceptRecheck);
            // The mark changes the projected site state without a result; open pages reload on state.changed (sent after commit).
            LiveSignal::forSite($check->site()->getQuery()->firstOrFail(), ['check' => $check->id]);
            activity('audit')->causedBy($actor)->performedOn($check)->event('dns.snapshot_accepted')
                ->withProperties(['accepted_run_id' => $runId])->log('dns.snapshot_accepted');

            return $attempt;
        });
    }
}
