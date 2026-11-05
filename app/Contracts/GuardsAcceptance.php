<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Cron consumes this optional fence when task definitions can change during evaluation. */
interface GuardsAcceptance
{
    public function revision(int $checkId): int;

    /** Only bounded DB-only evaluations may opt into serialized retries. */
    public function requiresSerialization(int $checkId): bool;

    /** Recorder calls under the parent check lock, setting on stale discard and clearing on acceptance. */
    public function serializeNext(int $checkId, bool $required): void;
}
