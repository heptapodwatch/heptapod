<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FindingsCoverage extends Data
{
    /** @param array<string, int|null>|null $counters
     * @param  array<string, int|null>|null  $limits
     */
    public function __construct(
        public readonly ?bool $discovery_complete,
        public readonly ?bool $crawl_truncated,
        public readonly ?bool $page_cap_reached,
        public readonly ?bool $resource_cap_reached,
        public readonly ?bool $time_limit_reached,
        public readonly ?bool $comparison_limit_reached,
        public readonly ?bool $stored_rows_complete,
        public readonly ?bool $stored_not_verifiable_complete,
        public readonly bool $unchecked_rows_available,
        public readonly bool $legacy,
        public readonly ?array $counters = null,
        public readonly ?array $limits = null,
    ) {}

    /** Retained run evidence can describe why staging was withheld, but cannot prove that a missing dataset expired.
     * @param  array<string, mixed>  $evidence
     */
    public static function forRetainedObservation(array $evidence): ?self
    {
        if (! array_key_exists('crawl_truncated', $evidence)) {
            return null;
        }
        $values = ['stored_rows_complete' => null, 'stored_not_verifiable_complete' => null,
            'unchecked_rows_available' => false, 'legacy' => true, 'counters' => [], 'limits' => []];
        foreach (['discovery_complete', 'crawl_truncated', 'page_cap_reached', 'resource_cap_reached', 'time_limit_reached', 'comparison_limit_reached'] as $flag) {
            $value = $evidence[$flag] ?? null;
            if ($value !== null && ! is_bool($value)) {
                throw new \LogicException('Retained findings coverage flags must be boolean.');
            }
            $values[$flag] = $value;
        }
        foreach (['observed_pages', 'observed_urls', 'discovered_urls', 'not_verifiable_count', 'unchecked_count', 'page_cap'] as $counter) {
            $value = $evidence[$counter] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new \LogicException('Retained findings coverage counters must be nonnegative integers.');
            }
            if ($counter === 'page_cap') {
                $values['limits'][$counter] = $value;
            } else {
                $values['counters'][$counter] = $value;
            }
        }

        return self::from($values);
    }

    public function comparable(): bool
    {
        return $this->discovery_complete === true && $this->crawl_truncated === false
            && $this->time_limit_reached === false && $this->comparison_limit_reached === false
            && $this->stored_rows_complete === true && $this->stored_not_verifiable_complete === true;
    }
}
