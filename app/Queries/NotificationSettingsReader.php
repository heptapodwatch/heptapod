<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Actions\Notifications\SaveNotificationChannel;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Data\Ui\ChannelTypeData;
use Heptapod\Data\Ui\NotificationChannelData;
use Heptapod\Data\Ui\NotificationSettingsPageData;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\SiteLabels;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Page props for RecipientSettings (design §7.1). One query per concern, never one per channel: channels, grid cells,
 * mutes, queued counts, the schedule and the site list are each read once for the whole recipient.
 */
final readonly class NotificationSettingsReader
{
    /** Audit event an Owner writes to hide the stored migration report on the team page for the whole instance. */
    public const string MigrationReportDismissed = 'notifications.migration_report_dismissed';

    public function __construct(private ChannelRegistry $channels, private SitePolicy $sites, private InstanceSettings $settings, private GenericSiteIcons $icons) {}

    public function team(User $viewer): NotificationSettingsPageData
    {
        $clients = [];
        $query = Client::query();
        $query->getQuery()->whereNull('archived_at')->orderBy('name');
        foreach ($query->get(['id', 'name']) as $client) {
            $clients[] = ['id' => $client->id, 'name' => $client->name];
        }

        return $this->page(RecipientKind::Team, null, $this->settings->name, $viewer, Site::query()->getQuery()->select('sites.*')->whereIntegerInRaw('sites.id', $this->sites->visibleSiteIds($viewer)),
            NotificationSchedule::defaults(RecipientKind::Team), $clients, $this->migrationReport());
    }

    public function user(User $user): NotificationSettingsPageData
    {
        $schedule = NotificationSchedule::defaults(RecipientKind::User, $user->id);
        $schedule->setRelation('user', $user);

        return $this->page(RecipientKind::User, $user->id, $user->name, $user, Site::query()->getQuery()->select('sites.*')->whereIntegerInRaw('sites.id', $this->sites->visibleSiteIds($user)), $schedule, []);
    }

    public function client(User $viewer, Client $client): NotificationSettingsPageData
    {
        $schedule = NotificationSchedule::defaults(RecipientKind::Client, clientId: $client->id);
        $schedule->setRelation('client', $client);

        return $this->page(RecipientKind::Client, $client->id, $client->name, $viewer,
            Site::query()->getQuery()->select('sites.*')->where('sites.client_id', $client->id), $schedule, []);
    }

    /**
     * The report the 2026-10-30 conversion stored once (audit notifications.migrated); never computed live. It shows until
     * the Owner dismisses it for the instance (audit notifications.migration_report_dismissed, written after the report).
     *
     * @return list<string>|null
     */
    private function migrationReport(): ?array
    {
        $latest = DB::table('activity_log')->whereIn('event', ['notifications.migrated', self::MigrationReportDismissed])->orderByDesc('id')
            ->first(['event', 'properties']);
        if ($latest === null || $latest->event === self::MigrationReportDismissed) {
            return null;
        }
        $stored = $latest->properties;
        $properties = is_string($stored) ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
        $lines = is_array($properties) ? ($properties['lines'] ?? null) : null;
        if (! is_array($lines) || ! array_is_list($lines)) {
            throw new \LogicException('The stored notification migration report must be a list of lines.');
        }

        return array_map(static fn (mixed $line): string => is_string($line) ? $line : throw new \LogicException('A migration report line is text.'), $lines);
    }

    /**
     * @param  list<array{id: int, name: string}>  $clients
     * @param  list<string>|null  $report
     */
    private function page(RecipientKind $kind, ?int $recipientId, string $name, User $viewer, Builder $sites, NotificationSchedule $default,
        array $clients, ?array $report = null): NotificationSettingsPageData
    {
        $query = NotificationDestination::query()->with(['user.roles', 'client']);
        $query->getQuery()->where('recipient_kind', $kind->value)->whereNull('removed_at')
            ->when($kind === RecipientKind::User, static fn (Builder $user): Builder => $user->where('user_id', $recipientId))
            ->when($kind === RecipientKind::Client, static fn (Builder $client): Builder => $client->where('client_id', $recipientId))
            ->orderByDesc('account_mail')->orderBy('id');
        $channels = $query->get();
        $ids = array_map(static fn (NotificationDestination $channel): int => $channel->id, $channels->all());
        /** @var array<int, array<string, string>> $cells */
        $cells = [];
        foreach (DB::table('notification_preferences')->whereIn('destination_id', $ids)->get(['destination_id', 'category', 'mode']) as $cell) {
            $cells[$this->int($cell, 'destination_id')][$this->string($cell, 'category')] = $this->string($cell, 'mode');
        }
        /** @var array<int, list<int>> $mutes */
        $mutes = [];
        foreach (DB::table('notification_site_mutes')->whereIn('destination_id', $ids)->orderBy('site_id')->get(['destination_id', 'site_id']) as $mute) {
            $mutes[$this->int($mute, 'destination_id')][] = $this->int($mute, 'site_id');
        }
        /** @var array<int, int> $queued */
        $queued = [];
        foreach (DB::table('deliveries')->whereIn('destination_id', $ids)->where('suppressed', false)->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value, DeliveryState::Sending->value])
            ->groupBy('destination_id')->selectRaw('destination_id, count(*) AS queued')->get() as $count) {
            $queued[$this->int($count, 'destination_id')] = $this->int($count, 'queued');
        }
        $stored = NotificationSchedule::query();
        $stored->getQuery()->where('recipient_kind', $kind->value)
            ->when($kind === RecipientKind::User, static fn (Builder $user): Builder => $user->where('user_id', $recipientId))
            ->when($kind === RecipientKind::Client, static fn (Builder $client): Builder => $client->where('client_id', $recipientId));
        $schedule = $stored->first() ?? $default;
        $schedule->setRelations($default->getRelations());
        $own = $kind === RecipientKind::User && $viewer->id === $recipientId;
        $grid = [];
        foreach (NotificationCategory::grid() as $category) {
            if ($category === NotificationCategory::System && ($kind === RecipientKind::Client || ($own && ! $viewer->hasAnyRole(['Owner', 'Admin'])))) {
                continue;
            }
            $modes = $category->allowsDigest() ? NotificationMode::cases() : [NotificationMode::Immediate, NotificationMode::Off];
            $grid[] = ['category' => $category->value, 'modes' => array_map(static fn (NotificationMode $mode): string => $mode->value, $modes)];
        }
        $visible = array_flip(array_column($grid, 'category'));
        $rows = [];
        foreach ($channels as $channel) {
            $defaults = $channel->default_preferences === null ? null : array_intersect_key($channel->default_preferences, $visible);
            // The System cell as SystemIssueMailer reads it from recipients(), so its rule runs on the loaded grid.
            $channel->setAttribute('system_mode', $cells[$channel->id][NotificationCategory::System->value] ?? null);
            $rows[] = $this->channel($channel, array_intersect_key($cells[$channel->id] ?? [], $visible), $defaults, $mutes[$channel->id] ?? [], $queued[$channel->id] ?? 0);
        }
        // Site exceptions name a site so it can be told apart from others of the same name: avatar, client and host.
        $siteRows = [];
        // The callers' base query already selects sites.*; get() would not narrow it, so the client name is added to it.
        foreach ($sites->join('clients', 'clients.id', '=', 'sites.client_id')->addSelect('clients.name AS client_name')
            ->orderBy('sites.friendly_name')->orderBy('sites.url')->get() as $site) {
            $friendly = ((array) $site)['friendly_name'] ?? null;
            $hash = ((array) $site)['icon_hash'] ?? null;
            $url = $this->string($site, 'url');
            $host = parse_url($url, PHP_URL_HOST);
            $siteRows[] = ['id' => $this->int($site, 'id'), 'name' => is_string($friendly) && $friendly !== '' ? $friendly : $url,
                'client_id' => $this->int($site, 'client_id'), 'client_name' => $this->string($site, 'client_name'),
                'host' => is_string($host) ? $host : $url, 'url' => $url, 'icon_url' => $this->icons->url($this->int($site, 'id'), is_string($hash) ? $hash : null),
                'icon_backing' => SiteIconBacking::from($this->string($site, 'icon_backing'))];
        }

        $labelSites = [];
        foreach ($siteRows as $site) {
            $labelSites[$site['id']] = ['name' => $site['name'], 'client' => $site['client_name'], 'url' => $site['url']];
        }
        $identities = SiteLabels::identities($labelSites);
        foreach ($siteRows as $index => $site) {
            $siteRows[$index]['identity'] = $identities[$site['id']];
        }

        return new NotificationSettingsPageData($kind, $recipientId, $name, $rows, $grid,
            ['quiet_start' => $schedule->quiet_start, 'quiet_end' => $schedule->quiet_end, 'quiet_weekends' => $schedule->quiet_weekends,
                'digest_time' => $schedule->digest_time, 'digest_weekdays' => $schedule->digest_weekdays, 'timezone' => $schedule->timezone()],
            $siteRows, $clients, array_values(array_filter(ChannelTypeData::installed($this->channels),
                static fn (ChannelTypeData $type): bool => SaveNotificationChannel::offers($kind, $type->key))), $report);
    }

    /**
     * @param  array<string, string>  $preferences
     * @param  array<string, string>|null  $defaults
     * @param  list<int>  $mutes
     */
    private function channel(NotificationDestination $channel, array $preferences, ?array $defaults, array $mutes, int $queued): NotificationChannelData
    {
        $values = $channel->destinationConfig()->values();
        $status = match (true) {
            $channel->needs_attention => 'needs_attention',
            ! $channel->enabled => 'paused',
            ! $channel->addressVerified() => 'unverified',
            default => 'active',
        };
        $email = $values['email'] ?? null;
        $reason = $channel->attention_reason === null ? null : __($channel->attention_reason);
        $systemLocked = SystemIssueMailer::systemLockedOn($channel);
        // Claim ignores pause for System on an account channel (design §3.4), so pausing it still lets system issues through.
        $systemBypassesPause = $channel->account_mail && SystemIssueMailer::ineligibility($channel) === null;
        if ($systemLocked && isset($preferences[NotificationCategory::System->value])) {
            $preferences[NotificationCategory::System->value] = NotificationMode::Immediate->value;
        }
        ksort($preferences);
        if ($defaults !== null) {
            ksort($defaults);
        }

        return new NotificationChannelData($channel->id, $channel->channel, $channel->label,
            $channel->channel === 'mail' && is_string($email) ? $email : null, $status, $channel->account_mail,
            $channel->locale, $channel->scope_client_id, is_string($reason) ? $reason : null,
            $channel->last_outcome, $channel->last_outcome_diagnosis, $channel->last_outcome_at?->toIso8601String(), $queued,
            $channel->config, array_map(NotificationMode::from(...), $preferences),
            $defaults === null ? null : array_map(NotificationMode::from(...), $defaults), $systemLocked, $systemBypassesPause, $mutes, ! $channel->account_mail);
    }

    private function int(object $row, string $column): int
    {
        $value = ((array) $row)[$column] ?? null;

        return is_int($value) ? $value : throw new \LogicException('Notification settings column '.$column.' must be an integer.');
    }

    private function string(object $row, string $column): string
    {
        $value = ((array) $row)[$column] ?? null;

        return is_string($value) ? $value : throw new \LogicException('Notification settings column '.$column.' must be text.');
    }
}
