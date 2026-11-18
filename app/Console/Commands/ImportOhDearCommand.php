<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heptapod:import-ohdear {--token= : Oh Dear API token (required)} {--dry-run : Preview the import without saving changes}')]
final class ImportOhDearCommand extends Command
{
    public function getDescription(): string
    {
        return __('import.description');
    }

    public function handle(ImportOhDearAccount $import): int
    {
        $token = $this->option('token');
        if (! is_string($token) || $token === '') {
            $this->error(__('import.token_required'));

            return self::FAILURE;
        }
        $dryRun = $this->option('dry-run') === true;
        $plan = ImportOhDearAccount::stopOnLocalFault(static fn (): ImportPlanData => $import($token, $dryRun), static fn (): null => null);
        if ($plan === null) {
            $this->error(__('import.local_error'));

            return self::FAILURE;
        }
        $this->info(__('import.description'));
        if ($dryRun) {
            $this->line(__('import.dry_run'));
        }
        $this->table([__('import.monitor'), __('import.operation'), __('import.details')],
            // A failed row's reason is a summary; the logged entry has the class and place (the web run adds its run id instead).
            array_map(static fn (ImportPlanRowData $row): array => [$row->source_id, __('import.'.$row->operation->value), $row->operation === ImportOperation::Failed ? $row->details.' '.__('import.cli_log_reference') : $row->details], $plan->rows));
        $this->line(__('alert_sensitivity.import_summary', [
            'inherited' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === true)),
            'overridden' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === false)),
        ]));
        if ($plan->remote_error !== null) {
            $this->error(__('import.incomplete'));
            $this->error($plan->remote_error);

            return self::FAILURE;
        }

        return $plan->failed() ? self::FAILURE : self::SUCCESS;
    }
}
