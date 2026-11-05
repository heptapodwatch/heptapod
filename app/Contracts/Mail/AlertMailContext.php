<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRunPresentationPartData;

final readonly class AlertMailContext
{
    /** @param list<EvidenceBlock> $blocks
     * @param  list<CheckRunPresentationPartData>  $additionalReasons
     */
    public function __construct(
        public ?int $incidentId,
        public AlertMailEvent $event,
        public CarbonImmutable $eventAt,
        public MailEventFacts $eventFacts,
        public string $timezone,
        public string $checkLabelKey,
        public CheckRunPresentationPartData $reason,
        public array $blocks,
        public ?string $avatarPng,
        public ?string $avatarCid,
        public string $checkUrl,
        public string $routingWhy,
        public string $routingUrl,
        public ?LetterTile $avatarTile = null,
        public string $routingLabelKey = 'emails.change_notifications',
        public ?CheckRunPresentationPartData $explanation = null,
        public MailTone $tone = MailTone::Down,
        public array $additionalReasons = [],
        public MailDesign $design = MailDesign::Branded,
    ) {}
}
