<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

/**
 * Localized facts shared by the HTML, text and preview outputs. Account emails have no evidence blocks.
 * The design picks the HTML layout; every builder passes it explicitly (account mail always Branded).
 */
final readonly class MailModel
{
    /** @param list<EvidenceBlock> $blocks
     * @param  array{label: string, url: string}|null  $primary
     * @param  array{label: string, url: string}|null  $secondary
     */
    public function __construct(
        public string $locale,
        public string $subject,
        public string $preheader,
        public string $headline,
        public string $footerWhy,
        public string $instance,
        public MailTone $tone = MailTone::Neutral,
        public ?string $lead = null,
        public ?string $client = null,
        public ?string $site = null,
        public ?string $chip = null,
        public ?string $stateMeta = null,
        public array $blocks = [],
        public ?array $primary = null,
        public ?array $secondary = null,
        public ?string $routingLabel = null,
        public ?string $routingUrl = null,
        public ?string $avatarPng = null,
        public ?string $avatarCid = null,
        public ?LetterTile $avatarTile = null,
        public MailDesign $design = MailDesign::Branded,
    ) {}
}
