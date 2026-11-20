<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** "Running now": the manual work in flight the viewer may see, shared with every page. */
#[TypeScript]
final class RunningNowData extends Data
{
    /**
     * @param  list<RunningCheckRunData>  $check_runs  the viewer's own runs first, then by request time; the viewer's
     *                                                 runs that finished in the last minute come first of all
     * @param  int  $active_check_runs  every manual check run in flight the viewer may see, shown or not
     * @param  list<RunningImportData>  $imports  Owner/Admin: imports in flight and the viewer's results of the last minute
     */
    public function __construct(
        public readonly array $check_runs,
        public readonly int $active_check_runs,
        public readonly array $imports,
        public readonly ?RunningBackupData $backup,
    ) {}
}
