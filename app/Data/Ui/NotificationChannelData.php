<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\NotificationMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One channel row in RecipientSettings (design §7.1). The address is masked unless the viewer manages the recipient. */
#[TypeScript]
final class NotificationChannelData extends Data
{
    /** @param array<string, mixed> $config public settings only, never secrets */
    public function __construct(
        public readonly int $id,
        public readonly string $channel,
        public readonly string $label,
        public readonly ?string $address,
        #[LiteralTypeScriptType("'active' | 'paused' | 'needs_attention' | 'unverified'")]
        public readonly string $status,
        public readonly bool $account_mail,
        public readonly ?string $locale,
        public readonly ?int $scope_client_id,
        public readonly ?string $attention_reason,
        public readonly ?string $last_outcome,
        public readonly ?string $last_outcome_diagnosis,
        public readonly ?string $last_outcome_at,
        public readonly int $queued_count,
        public readonly array $config,
        /** @var array<string, NotificationMode> category → mode */
        public readonly array $preferences,
        /** @var array<string, NotificationMode>|null the grid the channel started with (creation or the 2026-10-30 conversion); null when unknown */
        public readonly ?array $defaults,
        /** The System cell is on and cannot change: an Owner's account email (SystemIssueMailer::systemLockedOn). */
        public readonly bool $system_locked,
        /** System issues still reach this channel while it is paused (SystemIssueMailer::ineligibility ignores pause for account channels). */
        public readonly bool $system_bypasses_pause,
        /** @var list<int> */
        public readonly array $muted_site_ids,
        public readonly bool $can_remove,
    ) {}
}
