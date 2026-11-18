<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Data\Import\ImportChannelRowData;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\ImportSection;
use Heptapod\Enums\NotificationCategory;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heptapod:import-ohdear {--token= : Oh Dear API token (required)} {--dry-run : Preview the import without saving changes} {--only= : Comma-separated sections (sites,maintenance,cron,channels); defaults to all}')]
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
        $sections = ImportSection::cases();
        $only = $this->option('only');
        if ($this->input->hasParameterOption('--only', true)) {
            $values = is_string($only) ? array_map(trim(...), explode(',', $only)) : [''];
            $sections = [];
            foreach ($values as $value) {
                $section = ImportSection::tryFrom($value);
                if ($section === null || in_array($section, $sections, true)) {
                    $this->error(__('import.cli_sections_invalid'));

                    return self::FAILURE;
                }
                $sections[] = $section;
            }
        }
        $dryRun = $this->option('dry-run') === true;
        $plan = ImportOhDearAccount::stopOnLocalFault(static fn (): ImportPlanData => $import($token, $dryRun, $sections), static fn (): null => null);
        if ($plan === null) {
            $this->error(__('import.local_error'));

            return self::FAILURE;
        }
        $this->info(__('import.description'));
        $this->line(__('import.sections').': '.implode(', ', array_map(static fn (ImportSection $section): string => $section->label(), $plan->sections)));
        if ($dryRun) {
            $this->line(__('import.dry_run'));
        }
        if (in_array(ImportSection::Channels, $sections, true)) {
            $this->table([ImportSection::Channels->label(), __('import.operation'), __('import.details')], array_map(static function (ImportChannelRowData $row): array {
                $unassigned = $row->tags !== [] && $row->operation !== ImportOperation::Skipped;
                $labels = [...array_map(static fn (NotificationCategory $type): string => __('recipient_settings.category.'.$type->value.'.label'), $row->categories),
                    ...array_map(static fn (string $notice): string => __($notice), $row->notices)];
                if ($unassigned) {
                    $labels = [__('import.notice.channel_no_client')];
                }

                return [$row->source_id.' · '.__($row->kind_label), __('import.'.($unassigned ? 'skipped' : $row->operation->value)), implode('; ', $labels)];
            }, $plan->channels));
        }
        if ($plan->rows !== []) {
            $this->table([__('import.monitor'), __('import.operation'), __('import.details')],
                // A failed row's reason is a summary; the logged entry has the class and place (the web run adds its run id instead).
                array_map(static fn (ImportPlanRowData $row): array => [$row->source_id, __('import.'.$row->operation->value), $row->operation === ImportOperation::Failed ? $row->details.' '.__('import.cli_log_reference') : $row->details], $plan->rows));
        }
        if (in_array(ImportSection::Sites, $sections, true)) {
            $this->line(__('alert_sensitivity.import_summary', [
                'inherited' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === true)),
                'overridden' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === false)),
            ]));
        }
        if ($plan->remote_error !== null) {
            $this->error(__('import.incomplete'));
            $this->error($plan->remote_error);

            return self::FAILURE;
        }

        return $plan->failed() ? self::FAILURE : self::SUCCESS;
    }
}
