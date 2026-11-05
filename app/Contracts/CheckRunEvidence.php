<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Recorder facts describe acceptance/presentation, not an addon observation. */
final class CheckRunEvidence
{
    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public static function observation(array $evidence): array
    {
        return array_diff_key($evidence, array_flip(['verified_nothing', 'availability_failure', 'presentation_events']));
    }
}
