<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRunPresentationInput;

interface MailEvidenceProvider
{
    /** @return array<string, string> Routing event keys mapped to addon-owned sentence phrases. */
    public function mailEventPhrases(): array;

    /** @return array<string, array<string, MailTone>> Raw event types mapped to retained final results and mail tones. */
    public function mailEventTones(): array;

    /** @return list<EvidenceBlock> */
    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array;

    /** @return list<EvidenceBlock> Null recovery means raw retention removed the referenced accepted run. */
    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array;
}
