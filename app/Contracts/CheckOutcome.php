<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckOutcome extends Data
{
    /** @param array<string, int|float|string|bool|null> $summaryParams
     * @param  list<AddonEvent>  $events
     */
    public function __construct(
        public readonly RunResult $result,
        public readonly string $summary,
        public readonly array $summaryParams,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?Data $metrics,
        /** Bounded addon-defined DTO; never full crawl/health datasets or secrets. */
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?Data $evidence = null,
        public readonly array $events = [],
        public readonly ?CheckError $error = null,
        public readonly bool $incomplete = false,
        /** The incomplete run still confirms its Failed/Warning result: it escalates but never resolves an incident. */
        public readonly bool $confirmedIncompleteFailure = false,
        public readonly ?AvailabilityFailure $availabilityFailure = null,
        /** No site observation was verified; incomplete success cannot recover an incident. */
        public readonly bool $verifiedNothing = false,
    ) {
        if (! $metrics instanceof Data && $result !== RunResult::Errored) {
            throw new \InvalidArgumentException('Only an errored run may have no metrics.');
        }
        if (($result === RunResult::Errored) !== ($error instanceof CheckError)) {
            throw new \InvalidArgumentException('Only errored runs must carry engine diagnostics.');
        }
        if ($availabilityFailure instanceof AvailabilityFailure && ! in_array($result, [RunResult::Failed, RunResult::Errored], true)) {
            throw new \InvalidArgumentException('Only failed or errored observations may report an availability failure.');
        }
        if ($verifiedNothing && (! $incomplete || $confirmedIncompleteFailure)) {
            throw new \InvalidArgumentException('Verifying nothing requires an incomplete observation without a confirmed failure.');
        }
        if ($confirmedIncompleteFailure && (! $incomplete || ! in_array($result, [RunResult::Failed, RunResult::Warning], true))) {
            throw new \InvalidArgumentException('Only incomplete failed or warning runs may confirm their result.');
        }
    }
}
