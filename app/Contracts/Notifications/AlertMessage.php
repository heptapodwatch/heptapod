<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\Mail\AlertMailContext;
use Heptapod\Contracts\Mail\MailModel;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AlertMessage extends Data
{
    /** @param array<string, string|int|float> $titleParams
     * @param  array<string, string|int|float>  $summaryParams
     * @param  list<string>|null  $checkTypes
     * @param  list<string>  $affectedChecks
     * @param  list<string>  $recheckingChecks
     * @param  list<string>|null  $systemFacts  localized fact lines of a system issue; it has no site or check
     */
    public function __construct(
        public readonly int $eventId,
        public readonly AlertSeverity $severity,
        public readonly string $titleKey,
        public readonly array $titleParams,
        public readonly string $summaryKey,
        public readonly array $summaryParams,
        public readonly ?int $siteId,
        public readonly string $siteLabel,
        public readonly string $siteUrl,
        public readonly ?string $checkType,
        public readonly CarbonImmutable $since,
        public readonly ?int $downtimeSeconds,
        public readonly ?string $cause,
        public readonly ?string $link,
        public readonly bool $isTest = false,
        public readonly ?string $clientName = null,
        public readonly ?array $checkTypes = null,
        public readonly array $affectedChecks = [],
        public readonly array $recheckingChecks = [],
        #[Hidden, TypeScriptHidden]
        public readonly ?AlertMailContext $mailContext = null,
        #[Hidden, TypeScriptHidden]
        public readonly ?MailModel $mail = null,
        #[Hidden, TypeScriptHidden]
        public readonly ?CheckRunPresentationData $presentation = null,
        #[Hidden, TypeScriptHidden]
        public readonly bool $maintenanceEnded = false,
        #[Hidden, TypeScriptHidden]
        public readonly ?array $systemFacts = null,
    ) {
        if (! in_array($cause, ['failed', 'errored', null], true)) {
            throw new \InvalidArgumentException('Invalid alert cause.');
        }
        if ($since->getOffset() !== 0) {
            throw new \InvalidArgumentException('Alert since must be UTC.');
        }
        if ($link !== null && (filter_var($link, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('Alert link must be an absolute HTTP URL.');
        }
        if ($downtimeSeconds !== null && $downtimeSeconds < 0) {
            throw new \InvalidArgumentException('Downtime cannot be negative.');
        }
    }
}
