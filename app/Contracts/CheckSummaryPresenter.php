<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CheckSummaryPresenter
{
    /** @param array<string, string|int|float|bool|null> $params */
    public function presentSummary(string $summary, array $params): CheckRunPresentationPartData;
}
