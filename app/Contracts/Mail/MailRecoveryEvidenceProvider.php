<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRunPresentationInput;

/** The outage mail (uptime) compares the opening with the recovery; other recoveries use the event mail and mailEvidence(). */
interface MailRecoveryEvidenceProvider
{
    /** @return list<EvidenceBlock> Null recovery means raw retention removed the referenced accepted run. */
    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array;
}
