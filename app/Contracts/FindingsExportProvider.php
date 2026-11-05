<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Findings\FindingStatus;

interface FindingsExportProvider
{
    /**
     * Read the accepted physical snapshot, never the check's moving latest pointer.
     * Null means the requested snapshot is not retained for this check.
     * Results are plain, localized text; alternate finding groups can supply their own result labels.
     * Every row names its classification in status, the same one the findings API reports: a confirmed problem
     * (broken), a target that refused or could not be verified (not_verifiable), one that was not checked
     * (unchecked), or a collection limit (coverage_limit). Consumers never derive it from the localized result.
     *
     * @return iterable<array{url: string, result: string, status: FindingStatus, found_on: string|null, link_text: string|null, first_seen_at: string|null, after_first_complete_crawl: bool}>|null
     */
    public function exportFindings(CheckKey $type, int $checkId, RunReference $run): ?iterable;
}
