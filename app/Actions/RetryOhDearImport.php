<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\ImportRunStatus;
use Heptapod\Jobs\RunOhDearImport;
use Heptapod\Models\ImportRun;
use Heptapod\Support\ImportTokenStore;
use Illuminate\Validation\ValidationException;

final readonly class RetryOhDearImport
{
    public function __invoke(ImportRun $importRun): void
    {
        if (ImportTokenStore::get($importRun->id) === null) {
            throw ValidationException::withMessages(['token' => __('The Oh Dear token has expired. Show the plan again.')]);
        }
        // Atomic claim: of two concurrent retries only one moves the run back to queued.
        // Compare-and-set on the observed attempt: a retry that read an older attempt cannot claim a newer one.
        $query = ImportRun::query()->getQuery()->where('id', $importRun->id)->where('attempts', $importRun->attempts)->whereIn('status', [ImportRunStatus::Incomplete->value, ImportRunStatus::Failed->value]);
        $claimed = $query->update(['status' => ImportRunStatus::Queued->value, 'attempts' => $importRun->attempts + 1, 'updated_at' => now()]);
        abort_if($claimed !== 1, 409);
        RunOhDearImport::dispatch($importRun->id);
    }
}
