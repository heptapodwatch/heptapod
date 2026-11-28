<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRunPresentationInput;

interface MailEvidenceProvider
{
    /** Core mail tones of the lifecycle events; a mailEventTones() entry for one of these events replaces it. */
    public const array LifecycleTones = [
        'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
        'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
        'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
        'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
    ];

    /** @return array<string, string> Routing event keys mapped to addon-owned sentence phrases. */
    public function mailEventPhrases(): array;

    /** @return array<string, array<string, MailTone>> Raw addon event types (and any LifecycleTones overrides) mapped to retained final results and mail tones. */
    public function mailEventTones(): array;

    /** @return list<EvidenceBlock> */
    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array;
}
