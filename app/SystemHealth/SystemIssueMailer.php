<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\SystemIssueNotification;
use Heptapod\Enums\HealthStatus;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\SystemIssue;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * System issue mail and messages in the delivery ledger (design §3.4). Recipients are account channels of Owners and
 * opted-in Admins plus team channels with the System cell on; never site routing, mutes or client channels.
 */
final readonly class SystemIssueMailer
{
    /** Log-only mail is rechecked at this cadence, as system mail always was. */
    public const int RecheckSeconds = 300;

    public const string LogOnlyReason = 'mail_log_only';

    /**
     * System sends per evaluation pass. Each synchronous send is bounded by its transport timeout, so a batch keeps the
     * evaluation inside its 300 s lock even when some providers are slow.
     */
    public const int DueBatch = 20;

    public function __construct(private InstanceSettings $settings) {}

    /** A log or array transport accepts mail without delivering it; system mail stays pending until a real transport exists. */
    public static function canDeliver(): bool
    {
        return ! MailDeliveryMode::logOnly();
    }

    /**
     * Why a channel may not receive System now; null when it may. An Owner's account channel is always eligible
     * ("Owner locked on"), whatever its stored cell says; every other channel needs the System cell on.
     *
     * @return 'disabled'|'not_owner_or_opted_in_admin'|'destination_disabled'|'needs_attention'|null
     */
    public static function ineligibility(NotificationDestination $channel): ?string
    {
        if ($channel->recipient_kind === RecipientKind::Team) {
            return match (true) {
                ! self::cellOn($channel) => 'not_owner_or_opted_in_admin',
                $channel->needs_attention => 'needs_attention',
                ! $channel->enabled => 'destination_disabled',
                default => null,
            };
        }
        $user = $channel->user;
        if ($channel->recipient_kind !== RecipientKind::User || ! $channel->account_mail || $user === null) {
            return 'not_owner_or_opted_in_admin';
        }
        if ($user->disabled_at !== null) {
            return 'disabled';
        }

        return $user->hasRole('Owner') || ($user->hasRole('Admin') && self::cellOn($channel)) ? null : 'not_owner_or_opted_in_admin';
    }

    /** The System cell as the grid shows it: Owners' account channels read on and locked from the same rule claim applies. */
    public static function systemLockedOn(NotificationDestination $channel): bool
    {
        return $channel->account_mail && $channel->user?->hasRole('Owner') === true;
    }

    private static function cellOn(NotificationDestination $channel): bool
    {
        // recipients() loads the System cell with the channel; claim and probes read it directly.
        $mode = $channel->getAttributes()['system_mode'] ?? DB::table('notification_preferences')->where('destination_id', $channel->id)
            ->where('category', NotificationCategory::System->value)->value('mode');

        return $mode === NotificationMode::Immediate->value;
    }

    /** @return Collection<int, NotificationDestination> current eligible channels in id order */
    public function recipients(): Collection
    {
        // Bounded by the channel count, in three queries: channels with their System cell, their users, and those users'
        // roles. Team channels without the System cell on are dropped in SQL; ineligibility() stays the one rule.
        $query = NotificationDestination::query()->with('user.roles');
        $query->getQuery()->select('notification_destinations.*')
            ->selectSub(DB::table('notification_preferences')->select('mode')->whereColumn('destination_id', 'notification_destinations.id')
                ->where('category', NotificationCategory::System->value), 'system_mode')
            ->whereNull('removed_at')
            ->where(static fn (Builder $kinds): Builder => $kinds
                ->where(static fn (Builder $account): Builder => $account->where('recipient_kind', RecipientKind::User->value)->where('account_mail', true))
                ->orWhere(static fn (Builder $team): Builder => $team->where('recipient_kind', RecipientKind::Team->value)
                    ->whereExists(static fn (Builder $cell): Builder => $cell->from('notification_preferences')->whereColumn('destination_id', 'notification_destinations.id')
                        ->where('category', NotificationCategory::System->value)->where('mode', NotificationMode::Immediate->value))))
            ->orderBy('id');
        $channels = $query->get();

        return $channels->filter(static fn (NotificationDestination $channel): bool => self::ineligibility($channel) === null)->values()->toBase();
    }

    /** One opening event per issue, and one pending delivery per current recipient the kind can reach. */
    public function queueOpening(SystemIssue $issue): void
    {
        $event = $this->event($issue, 'system.issue_opened', $issue->opened_at, []);
        $this->insertDeliveries($event, $this->recipients()->filter(fn (NotificationDestination $channel): bool => $this->reachesOnOpen($issue->kind, $channel)));
    }

    /**
     * NotificationLocks below the issue (which the caller holds) for {@see self::queueRecovery()} and an accepted probe
     * (CompleteSystemProbe): every current recipient's channel (recoveries are inserted for them) and every channel of a
     * row recovery writes, then those rows in ascending id: the open probe rows, the opening's queued rows and, if given,
     * the completing delivery. Locking again what a transaction holds is a no-op, so both callers share it.
     */
    public function lockRecovery(int $issueId, ?int $completing = null): void
    {
        $eventIds = DB::table('events')->where('system_issue_id', $issueId)->whereIn('type', ['system.issue_opened', 'system.issue_resolved'])->pluck('id', 'type');
        $touched = DB::table('deliveries')->where('suppressed', false)->where(static fn (Builder $rows): Builder => $rows
            ->where(static fn (Builder $probes): Builder => $probes->where('event_id', $eventIds->get('system.issue_resolved'))
                ->where('last_reason', ClaimNotificationDelivery::SystemProbeReason))
            ->orWhere(static fn (Builder $openings): Builder => $openings->where('event_id', $eventIds->get('system.issue_opened'))->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])))
            ->get(['id', 'destination_id']);
        NotificationLocks::channels([$completing === null ? null : NotificationLocks::channelOf($completing), ...$touched->pluck('destination_id'),
            ...$this->recipients()->map(static fn (NotificationDestination $channel): int => $channel->id)]);
        NotificationLocks::deliveries([$completing, ...$touched->pluck('id')]);
    }

    /**
     * Recovery goes to channels whose opening was sent, intersected with current eligibility; kinds that cannot announce
     * their opening by mail tell every current recipient (non-mail only for a log-only mailer). Openings still pending at
     * resolution are suppressed: a recovery without an opening is noise.
     */
    public function queueRecovery(SystemIssue $issue): void
    {
        $this->lockRecovery($issue->id);
        $now = CarbonImmutable::now('UTC');
        $opening = Event::query()->where('system_issue_id', $issue->id)->where('type', 'system.issue_opened')->first();
        $sentChannelIds = [];
        if ($opening instanceof Event) {
            $pending = Delivery::query()->where('event_id', $opening->id)->where('suppressed', false);
            $pending->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value]);
            $pending->update(['suppressed' => true, 'suppression_reason' => 'resolved_before_delivery', 'suppression_evaluated_at' => $now, 'last_reason' => null]);
            /** @var list<int> $sentChannelIds */
            $sentChannelIds = Delivery::query()->where('event_id', $opening->id)->where('state', DeliveryState::Sent->value)->pluck('destination_id')->all();
        }
        $recipients = $this->recipients()->filter(fn (NotificationDestination $channel): bool => match ($issue->kind) {
            SystemIssueKind::MailLogOnly => $channel->channel !== 'mail' && in_array($channel->id, $sentChannelIds, true),
            SystemIssueKind::MailTransportDead => true,
            default => in_array($channel->id, $sentChannelIds, true),
        });
        $event = $this->event($issue, 'system.issue_resolved', $issue->resolved_at ?? $now, ['probe' => false]);
        // A dead transport resolved without its probe (another mail succeeded first): its waiting probe rows send normally.
        Delivery::query()->where('event_id', $event->id)->where('last_reason', ClaimNotificationDelivery::SystemProbeReason)->where('suppressed', false)
            ->update(['last_reason' => null, 'state' => DeliveryState::Pending, 'next_attempt_at' => $now, 'claim_token' => null, 'lease_until' => null]);
        $this->insertDeliveries($event, $recipients);
    }

    /**
     * The recovery for one channel whose opening was accepted after the issue had already resolved (a send that was in
     * flight during the resolution). Current eligibility applies, as for every recovery.
     */
    public function queueRecoveryFor(SystemIssue $issue, int $channelId): void
    {
        if ($issue->kind === SystemIssueKind::MailTransportDead) {
            return;
        }
        $event = $this->event($issue, 'system.issue_resolved', $issue->resolved_at ?? CarbonImmutable::now('UTC'), ['probe' => false]);
        $this->insertDeliveries($event, $this->recipients()->filter(static fn (NotificationDestination $channel): bool => $channel->id === $channelId
            && ($issue->kind !== SystemIssueKind::MailLogOnly || $channel->channel !== 'mail')));
    }

    /**
     * One event per issue and phase; a concurrent second insert is a no-op (unique (system_issue_id, type)).
     *
     * @param  array<string, bool>  $payload
     */
    public function event(SystemIssue $issue, string $type, CarbonImmutable $at, array $payload): Event
    {
        $existing = Event::query()->where('system_issue_id', $issue->id)->where('type', $type)->first();
        if ($existing instanceof Event) {
            return $existing;
        }
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('events')->insertOrIgnore(['type' => $type, 'system_issue_id' => $issue->id, 'occurred_at' => $at->format('Y-m-d H:i:s.uP'),
            'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR), 'suppressed' => false, 'suppression_evaluated_at' => $now,
            // System events are routed here, never by the event planner.
            'notification_routed_at' => $now]);

        return Event::query()->where('system_issue_id', $issue->id)->where('type', $type)->firstOrFail();
    }

    /**
     * @param  Collection<int, NotificationDestination>  $channels
     * @param  string|null  $reason  ClaimNotificationDelivery::SystemProbeReason for transport probe rows
     * @param  int  $probeRound  a re-armed transport probe after a rejection (SystemTransportProbe); 0 otherwise
     */
    public function insertDeliveries(Event $event, Collection $channels, ?string $reason = null, int $probeRound = 0): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('deliveries')->insertOrIgnore($channels->map(static fn (NotificationDestination $channel): array => [
            'event_id' => $event->id, 'destination_id' => $channel->id, 'probe_round' => $probeRound, 'state' => DeliveryState::Pending, 'next_attempt_at' => $now,
            'suppression_evaluated_at' => $now, 'last_reason' => $reason, 'origin' => 'system', 'category' => NotificationCategory::System->value,
        ])->values()->all());
    }

    /** A log-only mailer and a dead transport cannot deliver an opening mail: those kinds open on other channels only. */
    private function reachesOnOpen(SystemIssueKind $kind, NotificationDestination $channel): bool
    {
        return $kind->mailsOnOpen() || $channel->channel !== 'mail';
    }

    /**
     * Due System deliveries, sent synchronously inside the evaluation lock as system mail always was: this path must
     * work while queue workers are down. Probe rows belong to SystemTransportProbe.
     *
     * At most $limit rows, earliest due first, read through the deliveries_system_unfinished partial index; the rest
     * wait for the next pass (every 5 minutes). A failed row is due again 300 s later, so it moves behind rows that
     * have not been tried yet. Ordinary dispatch also claims due System rows meanwhile.
     *
     * @param  bool  $includeMail  false once the mail transport failed in this pass: only other channels can make progress
     * @return list<array{id: int, channel: string}>
     */
    public function due(int $limit = self::DueBatch, bool $includeMail = true): array
    {
        if ($limit < 1) {
            return [];
        }
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        $due = DB::table('deliveries')->join('notification_destinations as d', 'd.id', '=', 'deliveries.destination_id')
            ->where('deliveries.category', NotificationCategory::System->value)->where('deliveries.suppressed', false)
            ->whereIn('deliveries.state', [DeliveryState::Pending->value, DeliveryState::Failed->value, DeliveryState::Sending->value])
            ->where(static fn (Builder $rows): Builder => $rows->where(static fn (Builder $retry): Builder => $retry->whereIn('deliveries.state', [DeliveryState::Pending->value, DeliveryState::Failed->value])
                ->where('deliveries.next_attempt_at', '<=', $now))
                ->orWhere(static fn (Builder $expired): Builder => $expired->where('deliveries.state', DeliveryState::Sending->value)->where('deliveries.lease_until', '<=', $now)))
            ->whereRaw('(deliveries.last_reason IS NULL OR deliveries.last_reason <> ?)', [ClaimNotificationDelivery::SystemProbeReason])
            ->when(! $includeMail, static fn (Builder $rows): Builder => $rows->where('d.channel', '<>', 'mail'))
            ->orderBy('deliveries.next_attempt_at')->orderBy('deliveries.id')->limit($limit)->get(['deliveries.id', 'd.channel']);

        $rows = $due->map(static fn (object $row): array => ['id' => is_int($row->id) ? $row->id : throw new \LogicException('Delivery ids are integers.'),
            'channel' => is_string($row->channel) ? $row->channel : throw new \LogicException('Channel keys are strings.')])->values()->all();

        return array_values($rows);
    }

    /** The localized notification for one System delivery. */
    public function notification(Event $event, NotificationDestination $channel): SystemIssueNotification
    {
        $issue = SystemIssue::query()->findOrFail($event->system_issue_id);
        $recovered = $event->type === 'system.issue_resolved';
        if ($recovered && $issue->resolved_at === null) {
            // The transport probe submits the real recovery mail before the issue is resolved by its acceptance.
            $issue = clone $issue;
            $issue->resolved_at = CarbonImmutable::now('UTC');
        }
        $locale = $channel->effectiveLocale();
        $owner = self::systemLockedOn($channel);
        $mail = $this->model($issue, $recovered, $locale, $owner, $channel->recipient_kind === RecipientKind::Team);
        $facts = [];
        foreach ($mail->blocks as $block) {
            if ($block instanceof FactsBlock) {
                foreach ($block->rows as $row) {
                    $facts[] = $row['label'].': '.$row['value']->text;
                }
            }
        }
        $message = new AlertMessage($event->id, $recovered ? AlertSeverity::Recovered : ($issue->severity() === HealthStatus::Down ? AlertSeverity::Down : AlertSeverity::Warning),
            'system_mail.subject_'.($recovered ? 'recovered' : 'open'), ['title' => __('system_mail.'.$issue->kind->value.'.title', locale: $locale)], 'system_mail.facts', [],
            null, $this->settings->name, config()->string('app.url'), null, $issue->opened_at->utc(), null, null,
            route('settings.system.edit'), systemFacts: [$mail->lead ?? $mail->preheader, ...$facts]);

        return new SystemIssueNotification($message, $mail, $event->id.':'.$channel->id);
    }

    public function model(SystemIssue $issue, bool $recovered, string $locale, bool $owner, bool $team = false): MailModel
    {
        $title = __('system_mail.'.$issue->kind->value.'.title', locale: $locale);
        $time = fn (?string $at): string => $at === null ? __('system_mail.never', locale: $locale)
            : CarbonImmutable::parse($at)->setTimezone($this->settings->timezone)->format('Y-m-d H:i T');
        $rows = [['label' => __('system_mail.since', locale: $locale), 'value' => new Cell($time($issue->opened_at->toIso8601String()), true)]];
        if ($recovered) {
            $rows[] = ['label' => __('system_mail.resolved', locale: $locale), 'value' => new Cell($time($issue->resolved_at?->toIso8601String()), true)];
        }
        foreach ($issue->details as $key => $value) {
            // Retained read errors predate the typed reason and hold native text; mail names the reason instead.
            if ($key === 'error' && $issue->kind === SystemIssueKind::DiskSpaceLow) {
                continue;
            }
            if ($key === 'free_percent' && is_numeric($value)) {
                $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
                $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);
                $value = $formatter->format((float) $value);
                if ($value === false) {
                    throw new \RuntimeException('Cannot format filesystem free-space percentage.');
                }
            }
            if (in_array($key, ['loss_cause', 'resolution', 'check_type', 'no_result_cause', 'reason'], true) && is_string($value)) {
                $translated = __(match ($key) {
                    'no_result_cause' => $value, 'loss_cause' => 'freshness.loss.'.$value, 'check_type' => 'checks.'.$value.'.label',
                    'reason' => 'disk.read_failure.'.$value, default => 'system_mail.resolution.'.$value,
                }, $key === 'reason' ? ['path' => $issue->scope] : [], $locale);
                if (! is_string($translated)) {
                    throw new \LogicException('System issue detail translation must be a string.');
                }
                $value = $translated;
            }
            $rows[] = ['label' => __('system_mail.detail.'.$key, locale: $locale),
                'value' => new Cell(str_ends_with($key, '_at') || str_ends_with($key, '_since') ? $time(is_string($value) ? $value : null) : ($key === 'severity' ? __('system_mail.severity.'.$value, locale: $locale) : (string) ($value ?? '—')), true)];
        }
        $tone = $recovered ? MailTone::Recovered : ($issue->severity() === HealthStatus::Down ? MailTone::Failed : MailTone::Warning);
        $subject = __($recovered ? 'system_mail.subject_recovered' : 'system_mail.subject_open', ['title' => $title], $locale);
        $recoveryKey = $recovered && $issue->kind === SystemIssueKind::CheckNotFresh ? match ($issue->details['resolution'] ?? null) {
            'result_accepted' => 'recovered', 'monitoring_stopped' => 'stopped', 'check_deleted' => 'deleted', 'check_reset' => 'reset',
            default => throw new \LogicException('A freshness recovery must retain its resolution reason.'),
        } : 'recovered';
        $lead = $recovered ? __('system_mail.'.$issue->kind->value.'.'.$recoveryKey, locale: $locale) : __('system_mail.'.$issue->kind->value.'.action', locale: $locale);

        $headline = $recovered ? __('system_mail.headline_recovered', ['title' => $title], $locale) : $title;

        return new MailModel(locale: $locale, subject: $subject, preheader: $lead, headline: $headline,
            footerWhy: __($team ? 'system_mail.why_team' : ($owner ? 'system_mail.why_owner' : 'system_mail.why_admin'), locale: $locale),
            instance: $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST), tone: $tone, lead: $lead,
            blocks: [new FactsBlock(__('system_mail.facts', locale: $locale), $rows)],
            primary: ['label' => __('system_mail.open_system_health', locale: $locale), 'url' => route('settings.system.edit')], design: $this->settings->mail_design);
    }
}
