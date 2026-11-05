<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\ImportRunStatus;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Models\ImportRun;
use Heptapod\Support\ImportTokenStore;
use Illuminate\Validation\ValidationException;

final readonly class StartOhDearImport
{
    /** @param array<string, array{client_id: int|null, new_client: string|null}> $assignments */
    public function __invoke(ImportRun $importRun, array $assignments): void
    {
        if (ImportTokenStore::get($importRun->id) === null) {
            throw ValidationException::withMessages(['token' => __('The Oh Dear token has expired. Show the plan again.')]);
        }
        // Only a planned run can start; a concurrent second start finds nothing to claim.
        $claimed = ImportRun::query()->getQuery()->where('id', $importRun->id)->where('status', ImportRunStatus::Planned->value)
            ->update(['status' => ImportRunStatus::Queued->value, 'assignments' => json_encode($assignments, JSON_THROW_ON_ERROR), 'attempts' => 1, 'started_at' => now(), 'updated_at' => now()]);
        abort_if($claimed !== 1, 409);
        RunOhDearImport::dispatch($importRun->id);
    }
}
