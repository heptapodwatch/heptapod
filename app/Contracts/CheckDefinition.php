<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Schema\Schema;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckDefinition extends Data
{
    /** @param list<string> $emittedEvents
     * @param  class-string<CheckDetailData>|null  $detailDataClass  Addon-owned detail snapshot registered for generated UI types.
     */
    public function __construct(
        public readonly CheckKey $key,
        public readonly string $labelKey,
        public readonly string $icon,
        public readonly string $queue,
        public readonly int $timeout,
        public readonly int $defaultInterval,
        public readonly int $defaultFailureThreshold,
        public readonly ExecutionMode $executionMode,
        public readonly Schema $configSchema,
        public readonly Schema $metricSchema,
        public readonly array $emittedEvents = [],
        public readonly bool $requiresHttps = false,
        public readonly bool $recommended = true,
        public readonly ?string $detailDataClass = null,
        public readonly ?int $minimumIncreaseInMs = null,
    ) {
        if ($detailDataClass !== null && ! is_subclass_of($detailDataClass, CheckDetailData::class)) {
            throw new \InvalidArgumentException('Check detail DTO must extend the core contract.');
        }
        if ($minimumIncreaseInMs !== null && $minimumIncreaseInMs < 1) {
            throw new \InvalidArgumentException('A minimum metric increase must be positive.');
        }
        if ($timeout < 1 || $defaultInterval < 1 || $defaultFailureThreshold < 1) {
            throw new \InvalidArgumentException('Check timing and failure threshold must be positive.');
        }
        if (! in_array($queue, ['checks-fast', 'checks-crawl', 'checks-browser'], true)) {
            throw new \InvalidArgumentException('Unknown check queue.');
        }
    }
}
