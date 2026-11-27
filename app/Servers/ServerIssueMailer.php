<?php

declare(strict_types=1);

namespace Heptapod\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\SystemIssueNotification;
use Heptapod\Contracts\ServerIssueAlerts;
use Heptapod\Contracts\ServerIssueMessage;
use Heptapod\Contracts\ServerIssues;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\DeliveryDecision;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\Notifications\NotificationTiming;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server issue alerts in the delivery ledger, the Servers sibling of SystemIssueMailer. Recipients are the personal
 * channels of Owners and Admins and team channels without a client limit, with the Servers cell on; never site routing,
 * mutes or client channels. Like the Warning category they wait for quiet hours and may go to the digest.
 */
final readonly class ServerIssueMailer implements ServerIssueAlerts
{
    public const string OpenedType = 'server.issue_opened';

    public const string ResolvedType = 'server.issue_resolved';

    /** An opening held until its server's snooze ends, as a snoozed check's openings are. */
    public const string SnoozeReason = 'deferred_by_snooze';

    public function __construct(private NotificationTiming $timing, private ServersAccess $access, private ServerIssues $issues, private InstanceSettings $settings) {}

    /**
     * Why a channel may never receive server alerts; null when it may. Claim checks it again, so a channel limited to one
     * client or an Admin who lost the role stops receiving what was already planned.
     *
     * @return 'servers_team_only'|'servers_client_limited'|'servers_owner_or_admin_only'|'personal_user_disabled'|null
     */
    public static function ineligibility(NotificationDestination $channel): ?string
    {
        $user = $channel->user;

        return match ($channel->recipient_kind) {
            RecipientKind::Client => 'servers_team_only',
            RecipientKind::Team => $channel->scope_client_id === null ? null : 'servers_client_limited',
            RecipientKind::User => match (true) {
                $user === null => 'servers_team_only',
                $user->disabled_at !== null => 'personal_user_disabled',
                ! $user->hasAnyRole(['Owner', 'Admin']) => 'servers_owner_or_admin_only',
                default => null,
            },
        };
    }

    /**
     * The server alert part of DeliveryPlan, also for digest items: servers on, the channel may hear about servers, an
     * opening's issue is still open, and the server is not snoozed. A snooze holds an opening until it ends and silences
     * a recovery. Null when the alert may go.
     */
    public function refusal(Event $event, NotificationDestination $channel, CarbonImmutable $at): ?DeliveryDecision
    {
        $reason = match (true) {
            ! $this->access->enabled() => 'servers_disabled',
            default => self::ineligibility($channel) ?? ($channel->enabled ? null : 'destination_disabled'),
        };
        if ($reason !== null) {
            return DeliveryDecision::suppress($reason);
        }
        $opening = $event->type === self::OpenedType;
        if ($opening && DB::table('events')->where('server_issue_id', $event->server_issue_id)->where('type', self::ResolvedType)->exists()) {
            return DeliveryDecision::suppress('resolved_before_delivery');
        }
        $serverId = $event->payload['server_id'] ?? null;
        $snoozedUntil = is_int($serverId) ? $this->issues->snoozedUntil($serverId) : null;
        if ($snoozedUntil instanceof CarbonImmutable && $snoozedUntil->greaterThan($at)) {
            return $opening ? DeliveryDecision::hold($snoozedUntil, self::SnoozeReason, setReason: true) : DeliveryDecision::suppress('snooze');
        }

        return null;
    }

    public function opened(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts): void
    {
        $now = CarbonImmutable::now('UTC');
        $recipients = $this->recipients();
        // NotificationLocks: the issue (the caller's), the channels in ascending id, then their new deliveries.
        NotificationLocks::channels($recipients->keys());
        $eventId = $this->event($issueId, self::OpenedType, $serverId, $serverName, $at, $facts);
        $schedules = NotificationSchedule::forChannels($recipients);
        // A snoozed server's openings are held by the claim (refusal), so they are planned like any other.
        $this->insertDeliveries($eventId, array_values($recipients->map(function (NotificationDestination $channel) use ($schedules, $now): array {
            $schedule = $schedules[$channel->id];
            $digest = $channel->getAttribute('servers_mode') === NotificationMode::Digest->value;

            return ['destination_id' => $channel->id, 'digest' => $digest, 'last_reason' => null,
                'next_attempt_at' => $digest ? $this->timing->nextDailyDigest($now, $schedule->effectiveDigestWeekdays(), $schedule->digest_time, $schedule->timezone()) : $now];
        })->all()));
    }

    public function resolved(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts, ?string $silencedBy): void
    {
        $now = CarbonImmutable::now('UTC');
        $openingId = DB::table('events')->where('server_issue_id', $issueId)->where('type', self::OpenedType)->value('id');
        $openings = $openingId === null ? collect() : DB::table('deliveries')->where('event_id', $openingId)->get(['id', 'destination_id']);
        /** @var Collection<int, NotificationDestination> $recipients */
        $recipients = $silencedBy === null ? $this->recipients() : new Collection;
        // NotificationLocks: the issue (the caller's), the channels whose rows this writes or inserts, then the openings.
        NotificationLocks::channels([...$openings->pluck('destination_id'), ...$recipients->keys()]);
        NotificationLocks::deliveries($openings->pluck('id'));
        // An opening still waiting (quiet hours, snooze, retries, the next digest) ends with its issue.
        DB::table('deliveries')->where('event_id', $openingId)->where('suppressed', false)->whereNull('digest_event_id')
            ->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])
            ->update(['suppressed' => true, 'suppression_reason' => $silencedBy ?? 'resolved_before_delivery', 'suppression_evaluated_at' => $now->format('Y-m-d H:i:s.uP'), 'last_reason' => null]);
        // A digest line of a resolved issue never comes back, so the recurring digest read stays bounded by the open issues.
        DB::table('deliveries')->where('event_id', $openingId)->where('digest', true)->whereNull('digest_recurrence_ended_at')
            ->update(['digest_recurrence_ended_at' => $now->format('Y-m-d H:i:s.uP')]);
        $eventId = $this->event($issueId, self::ResolvedType, $serverId, $serverName, $at, $facts);
        if ($silencedBy !== null) {
            return;
        }
        // The recovery goes where the opening was sent on its own (or is being sent); a digest line needs none.
        /** @var list<int> $reached */
        $reached = DB::table('deliveries')->where('event_id', $openingId)->where('digest', false)->where('suppressed', false)
            ->whereIn('state', [DeliveryState::Sent->value, DeliveryState::Sending->value])->pluck('destination_id')->all();
        $this->insertDeliveries($eventId, array_values($recipients->only($reached)->map(static fn (NotificationDestination $channel): array => [
            'destination_id' => $channel->id, 'digest' => false, 'last_reason' => null, 'next_attempt_at' => $now,
        ])->all()));
    }

    public function snoozeChanged(array $openIssueIds): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        $held = DB::table('deliveries')->whereIn('event_id', DB::table('events')->select('id')->whereIn('server_issue_id', $openIssueIds)->where('type', self::OpenedType))
            ->where('suppressed', false)->where('state', DeliveryState::Pending->value)->where('last_reason', self::SnoozeReason)->where('next_attempt_at', '>', $now);
        $locked = NotificationLocks::deliveries((clone $held)->pluck('id'));
        if ($locked !== []) {
            DB::table('deliveries')->whereIn('id', $locked)->update(['next_attempt_at' => $now]);
        }
    }

    /**
     * The rest of DeliveryPlan for a server issue delivery that refusal() lets through: a recovery goes only after its
     * opening was sent to this channel (a suppressed opening is final, so its recovery is too), then verification and
     * quiet hours.
     */
    public function plan(Delivery $delivery, NotificationDestination $destination, Event $event, CarbonImmutable $at): DeliveryDecision
    {
        if ($event->type === self::ResolvedType) {
            $opening = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.server_issue_id', $event->server_issue_id)
                ->where('events.type', self::OpenedType)->where('deliveries.destination_id', $delivery->destination_id)->first(['deliveries.state', 'deliveries.suppressed']);
            if ($opening === null || $opening->suppressed === true || ! in_array($opening->state, [DeliveryState::Sent->value, DeliveryState::Sending->value], true)) {
                return DeliveryDecision::suppress('opening_not_delivered');
            }
            if ($opening->state === DeliveryState::Sending->value) {
                return DeliveryDecision::hold($at->addMinute());
            }
        }
        if (! $destination->addressVerified()) {
            return DeliveryDecision::hold($at->addHour(), 'personal_email_unverified', setReason: true);
        }
        $due = $this->timing->afterQuietHours(NotificationSchedule::forChannel($destination), $at);
        if ($due->greaterThan($at)) {
            return DeliveryDecision::hold($due);
        }
        if (in_array($delivery->last_reason, [self::SnoozeReason, 'personal_email_unverified'], true)) {
            $delivery->last_reason = null;
        }

        return DeliveryDecision::send();
    }

    /** The localized alert for one server issue delivery: the server issue mail, or its facts as lines elsewhere. */
    public function notification(Event $event, NotificationDestination $channel): SystemIssueNotification
    {
        $recovered = $event->type === self::ResolvedType;
        $locale = $channel->effectiveLocale();
        $message = $this->message($event, $locale, $channel->resolvedTimezone());
        $openedAt = $recovered ? DB::table('events')->where('server_issue_id', $event->server_issue_id)->where('type', self::OpenedType)->value('occurred_at') : null;
        $since = ! $recovered ? $event->occurred_at->utc()
            : CarbonImmutable::parse(is_string($openedAt) ? $openedAt : throw new \LogicException('A server issue recovery needs its opening.'))->utc();
        $mail = $this->model($message, $recovered, $locale, $channel->recipient_kind === RecipientKind::Team);
        $alert = new AlertMessage($event->id, $recovered ? AlertSeverity::Recovered : AlertSeverity::Warning,
            'server_mail.subject_'.($recovered ? 'recovered' : 'open'), ['title' => $message->title], 'server_mail.facts', [],
            null, $this->settings->name, config()->string('app.url'), null, $since, null, null, $message->url,
            systemFacts: [$message->lead, ...array_map(static fn (array $fact): string => $fact['label'].': '.$fact['value'], $message->facts)],
            server: ['id' => is_int($event->payload['server_id'] ?? null) ? $event->payload['server_id'] : throw new \LogicException('A server issue event needs its server id.'),
                'name' => $event->server_name ?? throw new \LogicException('A server issue event needs its server name.'), 'url' => $message->url]);

        return new SystemIssueNotification($alert, $mail, $event->id.':'.$channel->id);
    }

    /** The message for a server issue event in a locale, from its retained facts (Activity, digests, sends). */
    public function message(Event $event, string $locale, string $timezone): ServerIssueMessage
    {
        return $this->issues->message($event->payload, $event->server_name ?? throw new \LogicException('A server issue event needs its server name.'),
            $event->type === self::ResolvedType, $locale, $timezone);
    }

    private function model(ServerIssueMessage $message, bool $recovered, string $locale, bool $team): MailModel
    {
        return new MailModel(locale: $locale, subject: __($recovered ? 'server_mail.subject_recovered' : 'server_mail.subject_open', ['title' => $message->title], $locale),
            preheader: $message->lead, headline: $recovered ? __('server_mail.headline_recovered', ['title' => $message->title], $locale) : $message->title,
            footerWhy: __($team ? 'server_mail.why_team' : 'server_mail.why_personal', locale: $locale),
            instance: $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST), tone: $recovered ? MailTone::Recovered : MailTone::Warning,
            lead: $message->lead, blocks: [new FactsBlock(__('server_mail.facts', locale: $locale),
                array_map(static fn (array $fact): array => ['label' => $fact['label'], 'value' => new Cell($fact['value'], true)], $message->facts))],
            primary: $message->url === null ? null : ['label' => __('server_mail.open_server', locale: $locale), 'url' => $message->url], design: $this->settings->mail_design);
    }

    /**
     * Current recipients keyed by channel id, in id order, with their Servers cell as servers_mode. Bounded by the channel
     * count, in three queries: channels with the cell on, their users, and those users' roles.
     *
     * @return Collection<int, NotificationDestination>
     */
    private function recipients(): Collection
    {
        $query = NotificationDestination::query()->with('user.roles');
        $query->getQuery()->select('notification_destinations.*')
            ->selectSub(DB::table('notification_preferences')->select('mode')->whereColumn('destination_id', 'notification_destinations.id')
                ->where('category', NotificationCategory::Servers->value), 'servers_mode')
            ->whereNull('removed_at')->where('enabled', true)->where('recipient_kind', '<>', RecipientKind::Client->value)
            ->whereExists(static fn (Builder $cell): Builder => $cell->from('notification_preferences')->whereColumn('destination_id', 'notification_destinations.id')
                ->where('category', NotificationCategory::Servers->value)->where('mode', '<>', NotificationMode::Off->value))
            ->orderBy('id');

        return $query->get()->filter(static fn (NotificationDestination $channel): bool => self::ineligibility($channel) === null)->keyBy('id')->toBase();
    }

    /**
     * One event per issue and phase, routed here and never by the event planner; a second insert is a no-op.
     *
     * @param  array<string, string|int|float|null>  $facts
     */
    private function event(int $issueId, string $type, int $serverId, string $serverName, CarbonImmutable $at, array $facts): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('events')->insertOrIgnore(['type' => $type, 'server_issue_id' => $issueId, 'server_name' => $serverName,
            'occurred_at' => $at->utc()->format('Y-m-d H:i:s.uP'), 'payload' => json_encode(['server_id' => $serverId, ...$facts], JSON_THROW_ON_ERROR),
            'suppressed' => false, 'suppression_evaluated_at' => $now, 'notification_routed_at' => $now]);
        $id = DB::table('events')->where('server_issue_id', $issueId)->where('type', $type)->value('id');

        return is_int($id) ? $id : throw new \LogicException('A server issue event must exist after its insert.');
    }

    /** @param list<array{destination_id: int, digest: bool, last_reason: string|null, next_attempt_at: CarbonImmutable}> $rows */
    private function insertDeliveries(int $eventId, array $rows): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('deliveries')->insertOrIgnore(array_map(static fn (array $row): array => [...$row, 'event_id' => $eventId, 'probe_round' => 0,
            'state' => DeliveryState::Pending->value, 'next_attempt_at' => $row['next_attempt_at']->utc()->format('Y-m-d H:i:s.uP'),
            'suppression_evaluated_at' => $now, 'origin' => 'system', 'category' => NotificationCategory::Servers->value], $rows));
    }
}
