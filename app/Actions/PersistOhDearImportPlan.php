<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Models\ImportRun;
use Heptapod\Models\User;
use Heptapod\Support\ImportTokenStore;
use Illuminate\Support\Arr;

final readonly class PersistOhDearImportPlan
{
    public function __invoke(User $user, ImportPlanData $plan, #[\SensitiveParameter] string $token): ?string
    {
        $planId = null;
        // Only a complete plan is runnable: an unread or failed monitor has no approved client, so the user must plan again.
        if ($plan->remote_error === null && ! array_any($plan->rows, static fn (ImportPlanRowData $row): bool => $row->operation === ImportOperation::Failed)) {
            $run = ImportRun::query()->create(['user_id' => $user->id, 'source' => 'ohdear', 'status' => ImportRunStatus::Planned, 'expected' => count($plan->rows),
                'teams' => $plan->teams, 'plan' => array_map(static fn (ImportPlanRowData $row): array => Arr::except($row->toArray(), ['details']), $plan->rows),
                'results' => [], 'created_client_ids' => []]);
            ImportTokenStore::put($run->id, $token);
            $planId = $run->id;
        }

        return $planId;
    }
}
