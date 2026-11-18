<?php

declare(strict_types=1);

namespace Heptapod\Data\Import;

use Heptapod\Enums\ImportOperation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One source monitor in an import plan or result. Vendor-neutral so other import sources can reuse it. */
#[TypeScript]
final class ImportPlanRowData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $checks  Check keys imported as enabled.
     * @param  list<string>  $disabled_checks  Check keys imported with their settings but turned off, as at the source.
     * @param  list<string>  $notices  Lang keys (Support\OhDearImportNotices) for every skipped check, setting or monitor.
     * @param  string  $details  Plain-text CLI summary; the web UI shows it only as the reason of a failed import row.
     * @param  array{one_off: int, recurring: int}|null  $maintenance  Null when not planned.
     * @param  int|null  $site_id  Local site: the existing one on a plan, the created or updated one after an import.
     */
    public function __construct(
        public readonly string $source_id,
        public readonly ?string $url,
        public readonly ?string $label,
        public readonly ?int $team_id,
        public readonly array $tags,
        public readonly ImportOperation $operation,
        public readonly array $checks,
        public readonly array $disabled_checks,
        public readonly array $notices,
        public readonly string $details,
        public readonly ?int $site_id = null,
        public readonly ?string $client_name = null,
        public readonly ?bool $inherits_alert_timing = null,
        public readonly ?array $maintenance = null,
        public readonly ?int $cron_definitions = null,
    ) {}

    public function failed(): bool
    {
        return in_array($this->operation, [ImportOperation::Partial, ImportOperation::Failed], true);
    }
}
