<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CheckType
{
    public function definition(): CheckDefinition;

    /** A no-result reason is not an observation; exceptions become errored runs. */
    public function execute(CheckContext $context): CheckOutcome|NoResultReason;

    /** Addon-owned datasets, e.g. DNS baselines or crawl findings; null for metric-only checks. */
    public function persistence(): ?RunPersistence;
}
