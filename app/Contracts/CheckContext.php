<?php

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\PolicyRefusals;

final readonly class CheckContext implements \JsonSerializable
{
    /** @param array<string, mixed> $config Validated settings, including decrypted secret leaves. Never log.
     * @param  list<array{name: string, value: string}>  $crawlerHeaders  Decrypted ordered crawler headers. Execution only; never log.
     */
    public function __construct(
        public int $siteId,
        public int $checkId,
        public int $runId,
        public string $claimToken,
        public string $url,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $deadline,
        #[\SensitiveParameter] public array $config,
        #[\SensitiveParameter] public array $crawlerHeaders,
        public ?PreviousResult $previousResult,
        public ?HistoryReader $history = null,
        public ?int $acceptanceRevision = null,
        public ?RunControl $runControl = null,
        public ?RunProgress $runProgress = null,
        public bool $manualRun = false,
        public PolicyRefusals $policyRefusals = new PolicyRefusals,
        public ?int $uptimeIntervalSeconds = null,
    ) {}

    public function withAcceptanceRevision(int $revision): self
    {
        return clone ($this, ['acceptanceRevision' => $revision]);
    }

    public function withRunControl(RunControl $control, RunProgress $progress): self
    {
        return clone ($this, ['runControl' => $control, 'runProgress' => $progress]);
    }

    /** @return array{siteId: int, checkId: int, runId: int} */
    public function jsonSerialize(): array
    {
        return ['siteId' => $this->siteId, 'checkId' => $this->checkId, 'runId' => $this->runId];
    }

    /** Logging a context must not expose configuration secrets. */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
