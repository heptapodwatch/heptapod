<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckRunPresentationData extends Data
{
    #[Computed]
    public readonly int $more_reasons;

    #[Computed]
    public readonly int $headline_count;

    /** @param list<CheckRunPresentationPartData> $parts Complete, sorted state breakdown; the presenter chooses how many reasons the headline names.
     * @param  list<CheckRunPresentationPartData>  $changes  Change facts belong in metadata unless the run succeeded.
     * @param  list<CheckRunPresentationPartData>  $details  Secondary facts that are neither a problem nor a change, shown beside the changes.
     */
    public function __construct(
        public readonly array $parts,
        public readonly ?CheckRunPresentationPartData $explanation = null,
        public readonly ?CheckRunPresentationPartData $history_label = null,
        public readonly bool $names_check = false,
        public readonly int $headline_limit = 2,
        public readonly array $changes = [],
        public readonly array $details = [],
    ) {
        $this->headline_count = self::headlineCount(count($parts), $headline_limit);
        $this->more_reasons = max(0, count($parts) - $this->headline_count);
    }

    public static function headlineCount(int $total, int $limit): int
    {
        if ($total < 0 || $limit < 1) {
            throw new \InvalidArgumentException('Headline selection needs a nonnegative total and a positive limit.');
        }

        return min($total, $limit + ($total - $limit === 1 ? 1 : 0));
    }

    /** @param list<array{key: string, severity: RunResult, occurrences: int, part: CheckRunPresentationPartData}> $reasons */
    public static function forReasons(array $reasons): self
    {
        $severity = static fn (RunResult $result): int => match ($result) {
            RunResult::Errored => 3,
            RunResult::Failed => 2,
            RunResult::Warning => 1,
            RunResult::Succeeded => 0,
        };
        usort($reasons, static function (array $left, array $right) use ($severity): int {
            $severityDifference = $severity($right['severity']) <=> $severity($left['severity']);
            if ($severityDifference !== 0) {
                return $severityDifference;
            }
            $occurrenceDifference = $right['occurrences'] <=> $left['occurrences'];

            return $occurrenceDifference !== 0 ? $occurrenceDifference : strcmp($left['key'], $right['key']);
        });

        return new self(array_column($reasons, 'part'));
    }
}
