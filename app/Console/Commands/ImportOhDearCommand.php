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
use Heptapod\Support\ConsoleText;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;

#[Signature('heptapod:import-ohdear {--token= : Oh Dear API token (deprecated; visible in the process list)} {--token-stdin : Read the Oh Dear API token from standard input} {--dry-run : Preview the import without saving changes} {--only= : Comma-separated sections (sites,maintenance,cron,channels); defaults to all}')]
final class ImportOhDearCommand extends Command
{
    public function getDescription(): string
    {
        return __('import.description');
    }

    public function handle(ImportOhDearAccount $import): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
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
                $operation = $unassigned ? ImportOperation::Skipped : $row->operation;
                $categories = $operation === ImportOperation::Skipped ? [] : $row->categories;
                $notices = $unassigned ? ['import.notice.channel_no_client'] : $row->notices;
                $labels = [...array_map(static fn (NotificationCategory $type): string => __('recipient_settings.category.'.$type->value.'.label'), $categories),
                    ...array_map(static fn (string $notice): string => __($notice), $notices)];

                return [$row->source_id.' · '.__($row->kind_label), __('import.'.$operation->value), ConsoleText::sanitize(implode('; ', $labels))];
            }, $plan->channels));
        }
        if ($plan->rows !== []) {
            $this->table([__('import.monitor'), __('import.operation'), __('import.details')],
                // A failed row's reason is a summary; the logged entry has the class and place (the web run adds its run id instead).
                array_map(static fn (ImportPlanRowData $row): array => [$row->source_id, __('import.'.$row->operation->value), ConsoleText::sanitize($row->operation === ImportOperation::Failed ? $row->details.' '.__('import.cli_log_reference') : $row->details)], $plan->rows));
        }
        if (in_array(ImportSection::Sites, $sections, true)) {
            $this->line(__('alert_sensitivity.import_summary', [
                'inherited' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === true)),
                'overridden' => count(array_filter($plan->rows, static fn (ImportPlanRowData $row): bool => $row->inherits_alert_timing === false)),
            ]));
        }
        if ($plan->remote_error !== null) {
            $this->error(__('import.incomplete'));
            $this->error(ConsoleText::sanitize($plan->remote_error));

            return self::FAILURE;
        }

        return $plan->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function resolveToken(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? ($this->input->getStream() ?? STDIN) : STDIN;
        if ($this->option('token-stdin') === true) {
            $raw = stream_get_contents($stream);
            if ($raw === false) {
                throw new RuntimeException('Could not read the Oh Dear API token from standard input.');
            }
            $token = trim(rtrim($raw, "\r\n"));

            return $token === '' ? null : $token;
        }
        $option = $this->option('token');
        if (is_string($option) && $option !== '') {
            $this->warn(__('import.token_option_deprecated'));

            return $option;
        }
        $env = getenv('OH_DEAR_API_TOKEN');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if ($this->input->isInteractive() && stream_isatty($stream)) {
            $entered = $this->secret(__('import.token_prompt'), false);

            return is_string($entered) && trim($entered) !== '' ? trim($entered) : null;
        }

        return null;
    }
}
