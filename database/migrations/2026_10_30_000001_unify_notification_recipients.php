<?php

declare(strict_types=1);

use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Models\Delivery;
use Heptapod\Models\Site;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules, follows and per-destination noise settings become channels with a category grid, site mutes and one
 * schedule per recipient (design 2026-10-29 rev. 3 §1, §5). plan() only reads the old schema: the read-only
 * heptapod:notifications:migration-preview prints the same report before an upgrade freezes writers.
 */
return new class extends Migration
{
    private const array CategoryKeys = [
        'outage' => ['site.down'],
        'failure' => ['check.failing'],
        'check_error' => ['check.failing'],
        'warning' => ['check.warning'],
        // A domain check's warning is an expiry notice; its routing key was check.warning.
        'expiring' => ['certificate.expiring', 'check.warning'],
        'recovery' => ['site.recovered', 'check.recovered'],
        // NotificationCategory::ChangeKeys as released with this migration; later keys must not change what it did.
        'changes' => [
            'application_health.component_changed', 'certificate_health.certificate_changed', 'broken_links.new_broken_found',
            'mixed_content.new_finding', 'dns.records_changed', 'domain.expiry_changed', 'domain.registration_changed',
            'lighthouse.score_threshold_crossed', 'sitemap.issues_changed', 'scheduled_task.missed',
        ],
    ];

    private const string UnreachableKey = 'cron.task_result_changed';

    public function up(): void
    {
        // Migrator holds the PostgreSQL transaction. Keep writers out until the swap and the report finish.
        DB::statement('LOCK TABLE users, notification_destinations, notification_rules, notification_rule_destination, site_notification_follows, deliveries IN SHARE ROW EXCLUSIVE MODE');
        $plan = $this->plan();
        $this->addSchema();
        $this->apply($plan);
        $this->dropLegacy();
        $this->backfillDeliveries();
        activity('audit')->event('notifications.migrated')->withProperties(['lines' => $plan['lines']])->log('notifications.migrated');
    }

    public function down(): never
    {
        throw new LogicException('This migration converts notification rules, follows and noise settings into channels. Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
    }

    /**
     * The upgrade preflight's report: the same plan, read in a repeatable-read read-only transaction.
     *
     * @return list<string>
     */
    public function preview(): array
    {
        return DB::transaction(function (): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->plan()['lines'];
        });
    }

    /**
     * @return array{
     *     channels: array<int, array{recipient_kind: string, user_id: int|null, account_mail: bool, scope_client_id: int|null, locale: string|null, cells: array<string, string>, mutes: list<int>}>,
     *     account_channels: array<int, array{label: string, cells: array<string, string>}>,
     *     schedules: list<array{recipient_kind: string, user_id: int|null, quiet_start: string|null, quiet_end: string|null, quiet_weekends: bool, digest_time: string, digest_weekdays: list<int>|null}>,
     *     lines: list<string>
     * }
     */
    public function plan(): array
    {
        $lines = [];
        $instanceTimezone = $this->instanceTimezone();
        $siteClients = [];
        foreach ($this->rows(DB::table('sites')->orderBy('id'), ['id', 'client_id']) as $site) {
            $siteClients[$this->int($site, 'id')] = $this->int($site, 'client_id');
        }
        $allSites = array_keys($siteClients);
        $tagSites = [];
        foreach ($this->rows(DB::table('taggables')->where('taggable_type', (new Site)->getMorphClass()), ['tag_id', 'taggable_id']) as $tag) {
            $tagSites[$this->int($tag, 'tag_id')][] = $this->int($tag, 'taggable_id');
        }
        $roles = [];
        foreach ($this->rows(DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'user'), ['model_has_roles.model_id', 'roles.name']) as $role) {
            $roles[$this->int($role, 'model_id')][] = $this->string($role, 'name');
        }
        $users = [];
        foreach ($this->rows(DB::table('users')->orderBy('id'), ['id', 'email', 'name', 'locale', 'timezone', 'system_issue_mails']) as $user) {
            $users[$this->int($user, 'id')] = ['email' => $this->string($user, 'email'), 'name' => $this->string($user, 'name'),
                'locale' => $this->string($user, 'locale'), 'timezone' => $this->string($user, 'timezone'), 'system_issue_mails' => (bool) $user['system_issue_mails']];
        }
        $destinations = $this->rows(DB::table('notification_destinations')->orderBy('id'), ['id', 'user_id', 'label', 'channel', 'locale', 'config', 'config_secrets',
            'notification_types', 'timezone', 'quiet_start', 'quiet_end', 'quiet_weekends', 'digest_enabled', 'digest_time', 'digest_weekdays']);
        $rules = [];
        foreach ($this->rows(DB::table('notification_rules')->orderBy('id'), ['id', 'event_type', 'scope_type', 'scope_id', 'mute_inherited', 'digest', 'weekdays', 'window_start', 'window_end']) as $rule) {
            $weekdays = $this->jsonList($rule, 'weekdays');
            $rules[$this->int($rule, 'id')] = ['event_type' => $this->string($rule, 'event_type'), 'scope_type' => $this->string($rule, 'scope_type'),
                'scope_id' => $this->nullableInt($rule, 'scope_id'), 'mute_inherited' => (bool) $rule['mute_inherited'], 'digest' => (bool) $rule['digest'],
                'window' => $weekdays === null ? null : implode(',', $weekdays).' '.$this->nullableString($rule, 'window_start').'–'.$this->nullableString($rule, 'window_end')];
        }
        $links = [];
        foreach ($this->rows(DB::table('notification_rule_destination'), ['notification_rule_id', 'notification_destination_id']) as $link) {
            $links[$this->int($link, 'notification_destination_id')][] = $this->int($link, 'notification_rule_id');
        }
        $follows = [];
        foreach ($this->rows(DB::table('site_notification_follows')->orderBy('site_id'), ['site_id', 'user_id']) as $follow) {
            $follows[$this->int($follow, 'user_id')][] = $this->int($follow, 'site_id');
        }
        $mutedAt = [];
        foreach ($rules as $rule) {
            if ($rule['mute_inherited'] && $rule['scope_id'] !== null) {
                $mutedAt[$rule['event_type']][] = $rule['scope_id'];
            }
        }
        $ruleSites = [];
        foreach ($rules as $id => $rule) {
            $scope = $rule['scope_type'].($rule['scope_id'] === null ? '' : ' #'.$rule['scope_id']);
            $type = $rule['event_type'];
            if ($type === self::UnreachableKey) {
                $lines[] = "Rule #{$id} ({$type}, {$scope}): unreachable key dropped; cron results route as Changes, Warning and Recovery.";

                continue;
            }
            if (! in_array($type, array_merge(...array_values(self::CategoryKeys)), true)) {
                $lines[] = "Rule #{$id} ({$type}, {$scope}): unknown key dropped.";

                continue;
            }
            $scopeId = $rule['scope_id'] ?? 0;
            $sites = match ($rule['scope_type']) {
                'all' => $allSites,
                'client' => array_keys(array_filter($siteClients, static fn (int $client): bool => $client === $scopeId)),
                'site' => isset($siteClients[$scopeId]) ? [$scopeId] : [],
                'tag' => array_values(array_intersect($tagSites[$scopeId] ?? [], $allSites)),
                default => throw new LogicException('Unknown rule scope: '.$rule['scope_type']),
            };
            if ($rule['scope_type'] !== 'site') {
                $sites = array_values(array_diff($sites, $mutedAt[$type] ?? []));
            }
            // All-sites and client rules also cover sites added later: they convert even when no site exists today.
            if ($sites === [] && ! in_array($rule['scope_type'], ['all', 'client'], true)) {
                $lines[] = "Rule #{$id} ({$type}, {$scope}): reaches no current site; not converted.";

                continue;
            }
            if ($rule['window'] !== null) {
                $lines[] = "Rule #{$id} ({$type}, {$scope}): time window {$rule['window']} dropped; alerts are no longer held for it.";
            }
            $ruleSites[$id] = $sites;
        }

        $channels = [];
        $teamTuples = [];
        $schedules = [];
        $usersWithAccount = [];
        foreach ($destinations as $destination) {
            $id = $this->int($destination, 'id');
            $userId = $this->nullableInt($destination, 'user_id');
            $user = $userId === null ? null : ($users[$userId] ?? throw new LogicException("Destination #{$id} references a missing user."));
            $types = $this->jsonList($destination, 'notification_types');
            $accepts = static fn (string $key): bool => $types === null || in_array($key, $types, true);
            /** @var array<string, list<array{digest: bool, sites: list<int>, client: int|null}>> $contributions */
            $contributions = [];
            foreach ($links[$id] ?? [] as $ruleId) {
                $rule = $rules[$ruleId] ?? throw new LogicException("Destination #{$id} links a missing rule.");
                if (isset($ruleSites[$ruleId]) && $accepts($rule['event_type'])) {
                    $contributions[$rule['event_type']][] = ['digest' => $rule['digest'], 'sites' => $ruleSites[$ruleId],
                        'client' => $rule['scope_type'] === 'client' ? $rule['scope_id'] : null];
                }
            }
            if ($userId !== null && isset($follows[$userId])) {
                $followed = array_values(array_intersect($follows[$userId], $allSites));
                foreach (array_unique(array_merge(...array_values(self::CategoryKeys))) as $key) {
                    if ($accepts($key)) {
                        $contributions[$key][] = ['digest' => false, 'sites' => $followed, 'client' => null];
                    }
                }
            }
            $label = 'Channel #'.$id.' ('.$this->string($destination, 'label').')';
            $cells = [];
            $usedKeys = [];
            foreach (self::CategoryKeys as $category => $keys) {
                $allowsDigest = NotificationCategory::from($category)->allowsDigest();
                $modes = [];
                $missing = [];
                foreach ($keys as $key) {
                    if (! isset($contributions[$key])) {
                        $missing[] = $key;

                        continue;
                    }
                    $usedKeys[$key] = true;
                    foreach ($contributions[$key] as $contribution) {
                        $modes[] = $contribution['digest'] && $allowsDigest ? NotificationMode::Digest : NotificationMode::Immediate;
                    }
                }
                $mode = $modes === [] ? NotificationMode::Off
                    : (in_array(NotificationMode::Immediate, $modes, true) ? NotificationMode::Immediate : NotificationMode::Digest);
                if ($mode !== NotificationMode::Off && $missing !== []) {
                    $lines[] = "{$label}: {$category} turned on although ".implode(', ', $missing).' had no rule; those alerts are now delivered too.';
                }
                $cells[$category] = $mode->value;
            }
            $overlay = (bool) $destination['digest_enabled'];
            if ($overlay) {
                // Today's personal digest gates by severity (NotificationTiming): Warning always, Error for personal destinations.
                foreach (['warning', 'expiring', ...($userId !== null ? ['check_error'] : [])] as $category) {
                    if ($cells[$category] !== NotificationMode::Off->value) {
                        $cells[$category] = NotificationMode::Digest->value;
                    }
                }
            }
            // Every routed key whose delivery timing changes is reported with its old and new timing.
            foreach (self::CategoryKeys as $category => $keys) {
                $now = $this->effectiveTiming($category, NotificationMode::from($cells[$category]));
                foreach ($keys as $key) {
                    if (! isset($contributions[$key])) {
                        continue;
                    }
                    // The old engine chose per site among the rules reaching it; the grid has one timing for every site.
                    $bySite = [];
                    foreach (array_unique(array_merge(...array_column($contributions[$key], 'sites'))) as $site) {
                        $reaching = array_values(array_filter($contributions[$key], static fn (array $contribution): bool => in_array($site, $contribution['sites'], true)));
                        $bySite[$this->legacyTiming($category, $reaching, $overlay, $userId !== null)][] = $site;
                    }
                    if ($bySite === []) {
                        // All-sites or client rules on an install without sites yet.
                        $bySite[$this->legacyTiming($category, $contributions[$key], $overlay, $userId !== null)] = [];
                    }
                    $total = array_sum(array_map(count(...), $bySite));
                    foreach ($bySite as $before => $sites) {
                        if ($before === $now) {
                            continue;
                        }
                        $where = count($bySite) > 1 ? ' on '.count($sites).' of '.$total.' sites' : '';
                        $lines[] = "{$label}: {$key} alerts were {$before}{$where}, now {$now} as {$category}.";
                    }
                }
            }
            $role = $user === null ? null : $this->primaryRole($roles[$userId] ?? []);
            $account = false;
            if ($user !== null) {
                $address = $this->address($destination, $id);
                $account = $destination['channel'] === 'mail' && $address !== null && mb_strtolower(trim($address)) === mb_strtolower(trim($user['email']));
                if (! $account) {
                    $lines[] = "{$label}: address differs from the account email of user #{$userId}; kept as a separate address.";
                }
                $locale = $this->string($destination, 'locale');
                if ($locale !== $user['locale']) {
                    $lines[] = "{$label}: language follows the account ({$user['locale']}) instead of {$locale}.";
                }
                $old = $this->nullableString($destination, 'timezone') ?? $instanceTimezone;
                if ($old !== $user['timezone']) {
                    $lines[] = "{$label}: quiet hours and digest use the account time zone {$user['timezone']} instead of {$old}.";
                }
                if ($account) {
                    $usersWithAccount[$userId] = true;
                }
                $schedules[$userId] ??= ['recipient_kind' => 'user', 'user_id' => $userId, ...$this->tuple($destination)];
            } else {
                $old = $this->nullableString($destination, 'timezone') ?? $instanceTimezone;
                if ($old !== $instanceTimezone) {
                    $lines[] = "{$label}: quiet hours and digest use the instance time zone {$instanceTimezone} instead of {$old}.";
                }
                $teamTuples[$id] = $this->tuple($destination);
            }
            // System mail always went to the account address, so only the account channel carries it.
            $cells['system'] = $account && $this->systemByDefault($role, $user['system_issue_mails'] ?? false) ? NotificationMode::Immediate->value : NotificationMode::Off->value;

            $siteSets = [];
            $scopes = [];
            foreach (array_keys($usedKeys) as $key) {
                $sites = array_values(array_unique(array_merge(...array_column($contributions[$key], 'sites'))));
                sort($sites);
                $siteSets[implode(',', $sites)] = $sites;
                foreach ($contributions[$key] as $contribution) {
                    $scopes[$contribution['client'] === null ? 'other' : 'client:'.$contribution['client']] = $contribution['client'];
                }
            }
            $scopeClient = null;
            $mutes = [];
            $reached = $siteSets === [] ? [] : array_values(array_unique(array_merge(...array_values($siteSets))));
            sort($reached);
            $clientOf = static fn (int $client): array => array_keys(array_filter($siteClients, static fn (int $owner): bool => $owner === $client));
            $onlyClient = count($scopes) === 1 && reset($scopes) !== null ? reset($scopes) : null;
            if ($siteSets === []) {
                // No rule or follow reaches this channel; its grid is all off.
            } elseif ($user === null && $onlyClient !== null) {
                // Client rules also cover that client's future sites, even when it has none today. Sites a site rule
                // excluded (mute_inherited) stay muted inside that client.
                $scopeClient = $onlyClient;
                $mutes = array_values(array_diff($clientOf($onlyClient), $reached));
                if ($mutes !== []) {
                    $lines[] = "{$label}: ".count($mutes).' sites of client #'.$onlyClient.' muted to keep today\'s site selection; sites added later are not muted.';
                }
            } else {
                if (count($siteSets) > 1) {
                    $lines[] = "{$label}: alert types reached different sites; every type now reaches the union of ".count($reached).' sites.';
                }
                $clients = array_values(array_unique(array_map(static fn (int $site): int => $siteClients[$site], $reached)));
                if ($reached === $allSites) {
                    // Every current site, and (all-sites rules) every future site, including an install without sites yet.
                } elseif ($user === null && count($clients) === 1 && $reached === $clientOf($clients[0])) {
                    $scopeClient = $clients[0];
                } else {
                    $mutes = array_values(array_diff($allSites, $reached));
                    $lines[] = "{$label}: ".count($mutes).' sites muted to keep today\'s site selection; sites added later are not muted.';
                }
            }
            $channels[$id] = ['recipient_kind' => $user === null ? 'team' : 'user', 'user_id' => $userId, 'account_mail' => $account,
                'scope_client_id' => $scopeClient, 'locale' => $user === null ? $this->string($destination, 'locale') : null, 'cells' => $cells, 'mutes' => $mutes];
        }

        $accountChannels = [];
        foreach ($users as $userId => $user) {
            if (isset($usersWithAccount[$userId])) {
                continue;
            }
            $cells = array_fill_keys(array_keys(self::CategoryKeys), NotificationMode::Off->value);
            $cells['system'] = $this->systemByDefault($this->primaryRole($roles[$userId] ?? []), $user['system_issue_mails'])
                ? NotificationMode::Immediate->value : NotificationMode::Off->value;
            $accountChannels[$userId] = ['label' => $user['name'], 'cells' => $cells];
            $schedules[$userId] ??= ['recipient_kind' => 'user', 'user_id' => $userId, 'quiet_start' => null, 'quiet_end' => null,
                'quiet_weekends' => false, 'digest_time' => '08:00', 'digest_weekdays' => [1, 2, 3, 4, 5]];
            $lines[] = "User #{$userId}: account email channel added with every alert category off, as before (System: {$cells['system']}).";
        }

        $team = ['quiet_start' => null, 'quiet_end' => null, 'quiet_weekends' => false, 'digest_time' => '08:00', 'digest_weekdays' => null];
        if ($teamTuples !== []) {
            $counts = [];
            $firstWithTuple = [];
            foreach ($teamTuples as $destinationId => $tuple) {
                $key = json_encode($tuple, JSON_THROW_ON_ERROR);
                $firstWithTuple[$key] ??= $destinationId;
                $counts[$firstWithTuple[$key]] = ($counts[$firstWithTuple[$key]] ?? 0) + 1;
            }
            // Most common tuple; array order is destination id order, so the lowest id wins a tie.
            $team = $teamTuples[(int) array_search(max($counts), $counts, true)];
            if (count($counts) > 1) {
                $lines[] = 'Team schedule: team channels had '.count($counts).' different quiet-hour/digest settings; the most common one now applies to all team channels.';
            }
        }
        $schedules = [...array_values($schedules), ['recipient_kind' => 'team', 'user_id' => null, ...$team]];
        $summary = [];
        foreach ($channels as $id => $channel) {
            $grid = implode(', ', array_map(static fn (string $category, string $mode): string => $category.'='.$mode, array_keys($channel['cells']), $channel['cells']));
            $scope = $channel['scope_client_id'] !== null ? '; client #'.$channel['scope_client_id'] : '';
            $summary[] = "Channel #{$id} ({$channel['recipient_kind']}".($channel['account_mail'] ? ', account email' : '')."{$scope}): {$grid}; ".count($channel['mutes']).' muted sites.';
        }
        $lines = [...$summary, ...$lines];

        return ['channels' => $channels, 'account_channels' => $accountChannels, 'schedules' => $schedules, 'lines' => $lines];
    }

    /**
     * Raw rows parsed once at the boundary; every later step works on typed values.
     *
     * @param  list<string>  $columns
     * @return list<array<string, int|string|bool|null>>
     */
    private function rows(Builder $query, array $columns): array
    {
        $rows = [];
        foreach ($query->get($columns) as $row) {
            $values = [];
            foreach ((array) $row as $column => $value) {
                $column = (string) $column;
                if ($value !== null && ! is_int($value) && ! is_string($value) && ! is_bool($value)) {
                    throw new LogicException('Unexpected value in notification column '.$column.'.');
                }
                $values[$column] = $value;
            }
            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * Read from the settings store, not the InstanceSettings class: the upgrade preview runs this plan before later
     * settings migrations, and today's class requires every property it has gained since.
     */
    private function instanceTimezone(): string
    {
        $payload = DB::table('settings')->where('group', 'instance')->where('name', 'timezone')->value('payload');
        $timezone = is_string($payload) ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : null;

        return is_string($timezone) ? $timezone : throw new LogicException('The instance time zone setting is missing.');
    }

    /** @param array<string, int|string|bool|null> $row */
    private function int(array $row, string $column): int
    {
        return is_int($row[$column]) ? $row[$column] : (is_string($row[$column]) && ctype_digit($row[$column]) ? (int) $row[$column]
            : throw new LogicException('Notification column '.$column.' must be an integer.'));
    }

    /** @param array<string, int|string|bool|null> $row */
    private function nullableInt(array $row, string $column): ?int
    {
        return $row[$column] === null ? null : $this->int($row, $column);
    }

    /** @param array<string, int|string|bool|null> $row */
    private function string(array $row, string $column): string
    {
        return is_string($row[$column]) ? $row[$column] : throw new LogicException('Notification column '.$column.' must be text.');
    }

    /** @param array<string, int|string|bool|null> $row */
    private function nullableString(array $row, string $column): ?string
    {
        return $row[$column] === null ? null : $this->string($row, $column);
    }

    /**
     * @param  array<string, int|string|bool|null>  $row
     * @return list<int|string>|null
     */
    private function jsonList(array $row, string $column): ?array
    {
        if ($row[$column] === null) {
            return null;
        }
        $value = json_decode($this->string($row, $column), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! array_is_list($value) || array_any($value, static fn (mixed $item): bool => ! is_int($item) && ! is_string($item))) {
            throw new LogicException('Notification column '.$column.' must be a JSON list.');
        }

        /** @var list<int|string> $value */
        return $value;
    }

    /**
     * Timing of one routing key before the conversion (NotificationTiming): a rule digest or the destination digest
     * only ever delayed Warning and Error severities, the destination digest Error only for personal destinations.
     *
     * @param  list<array{digest: bool, sites: list<int>, client: int|null}>  $contributions
     */
    private function legacyTiming(string $category, array $contributions, bool $overlay, bool $personal): string
    {
        $timings = [];
        foreach ($contributions as $contribution) {
            $warning = $contribution['digest'] || $overlay;
            $error = $contribution['digest'] || ($overlay && $personal);
            $timings[] = match ($category) {
                'warning', 'expiring' => $warning ? 'digest' : 'immediate',
                'check_error' => $error ? 'digest' : 'immediate',
                // Change events carry their own result: only their Warning and Error results could wait for the digest.
                'changes' => $warning || $error ? 'partly digest' : 'immediate',
                default => 'immediate',
            };
        }

        // The earliest due route won for a destination with several rules.
        return in_array('immediate', $timings, true) ? 'immediate' : (in_array('partly digest', $timings, true) ? 'partly digest' : 'digest');
    }

    /** Timing of one grid cell after the conversion; a Changes digest still sends Down and Info results at once. */
    private function effectiveTiming(string $category, NotificationMode $mode): string
    {
        return match (true) {
            $mode === NotificationMode::Off => 'off',
            $mode === NotificationMode::Digest => $category === 'changes' ? 'partly digest' : 'digest',
            default => 'immediate',
        };
    }

    /** @param list<string> $roles */
    private function primaryRole(array $roles): ?string
    {
        foreach (['Owner', 'Admin', 'Member'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    /** Owners always got system issue mail; Admins after opting in (SystemIssueMailer). */
    private function systemByDefault(?string $role, bool $optedIn): bool
    {
        return $role === 'Owner' || ($role === 'Admin' && $optedIn);
    }

    /** @param array<string, int|string|bool|null> $destination */
    private function address(array $destination, int $id): ?string
    {
        // Only this boundary decrypts stored secrets. Diagnostics never carry payloads.
        try {
            $public = json_decode($this->string($destination, 'config'), true, flags: JSON_THROW_ON_ERROR);
            $secret = $this->nullableString($destination, 'config_secrets');
            $secrets = $secret === null ? [] : json_decode(Crypt::decryptString($secret), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new LogicException("Destination #{$id} has an unreadable configuration.");
        }
        $email = array_replace(is_array($public) ? $public : [], is_array($secrets) ? $secrets : [])['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * @param  array<string, int|string|bool|null>  $destination
     * @return array{quiet_start: string|null, quiet_end: string|null, quiet_weekends: bool, digest_time: string, digest_weekdays: list<int>|null}
     */
    private function tuple(array $destination): array
    {
        $weekdays = $this->jsonList($destination, 'digest_weekdays');

        return ['quiet_start' => $this->nullableString($destination, 'quiet_start'), 'quiet_end' => $this->nullableString($destination, 'quiet_end'),
            'quiet_weekends' => (bool) $destination['quiet_weekends'], 'digest_time' => $this->string($destination, 'digest_time'),
            'digest_weekdays' => $weekdays === null ? null : array_map(intval(...), $weekdays)];
    }

    private function addSchema(): void
    {
        $categories = "'".implode("', '", array_map(static fn (NotificationCategory $category): string => $category->value, NotificationCategory::cases()))."'";
        $digestible = "'".implode("', '", array_map(static fn (NotificationCategory $category): string => $category->value,
            array_filter(NotificationCategory::cases(), static fn (NotificationCategory $category): bool => $category->allowsDigest())))."'";
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->string('recipient_kind')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('scope_client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->boolean('account_mail')->default(false);
            $table->timestampTz('removed_at', 6)->nullable();
            $table->string('removed_reason')->nullable();
            $table->string('locale', 2)->nullable()->default(null)->change();
            $table->dropUnique(['user_id']);
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['recipient_kind', 'client_id']);
        });
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->foreignId('destination_id')->constrained('notification_destinations')->cascadeOnDelete();
            $table->string('category');
            $table->string('mode');
            $table->primary(['destination_id', 'category']);
            $table->index(['category', 'mode']);
        });
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_category CHECK (category IN ({$categories}))");
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_mode CHECK (mode IN ('immediate', 'digest', 'off') AND (mode <> 'digest' OR category IN ({$digestible})))");
        Schema::create('notification_site_mutes', function (Blueprint $table): void {
            $table->foreignId('destination_id')->constrained('notification_destinations')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at', 6);
            $table->primary(['destination_id', 'site_id']);
            $table->index('site_id');
        });
        Schema::create('notification_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_kind');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->boolean('quiet_weekends')->default(false);
            $table->string('digest_time', 5)->default('08:00');
            $table->jsonb('digest_weekdays')->nullable();
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE notification_schedules ADD CONSTRAINT notification_schedules_recipient CHECK ((recipient_kind = 'team' AND user_id IS NULL AND client_id IS NULL) OR (recipient_kind = 'user' AND user_id IS NOT NULL AND client_id IS NULL) OR (recipient_kind = 'client' AND client_id IS NOT NULL AND user_id IS NULL))");
        DB::statement('ALTER TABLE notification_schedules ADD CONSTRAINT notification_schedules_quiet CHECK ((quiet_start IS NULL) = (quiet_end IS NULL))');
        DB::statement("CREATE UNIQUE INDEX notification_schedules_team ON notification_schedules (recipient_kind) WHERE recipient_kind = 'team'");
        DB::statement('CREATE UNIQUE INDEX notification_schedules_user ON notification_schedules (user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX notification_schedules_client ON notification_schedules (client_id) WHERE client_id IS NOT NULL');
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('origin')->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('actor_name')->nullable();
            $table->string('category')->nullable();
            $table->jsonb('recipient_snapshot')->nullable();
            $table->index(['destination_id', 'id']);
        });
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_origin CHECK (origin IN ('system', 'manual', 'scheduled') AND (origin <> 'manual' OR recipient_snapshot IS NOT NULL))");
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_category CHECK (category IS NULL OR category IN ({$categories}))");
    }

    /** @param array{channels: array<int, array{recipient_kind: string, user_id: int|null, account_mail: bool, scope_client_id: int|null, locale: string|null, cells: array<string, string>, mutes: list<int>}>, account_channels: array<int, array{label: string, cells: array<string, string>}>, schedules: list<array{recipient_kind: string, user_id: int|null, quiet_start: string|null, quiet_end: string|null, quiet_weekends: bool, digest_time: string, digest_weekdays: list<int>|null}>, lines: list<string>} $plan */
    private function apply(array $plan): void
    {
        $now = now();
        $preferences = [];
        $mutes = [];
        foreach ($plan['channels'] as $id => $channel) {
            $changes = ['recipient_kind' => $channel['recipient_kind'], 'account_mail' => $channel['account_mail'],
                'scope_client_id' => $channel['scope_client_id'], 'locale' => $channel['locale']];
            if ($channel['account_mail']) {
                // The address is derived from users.email while the user exists.
                $changes['config'] = DB::raw("config - 'email'");
            }
            DB::table('notification_destinations')->where('id', $id)->update($changes);
            foreach ($channel['cells'] as $category => $mode) {
                $preferences[] = ['destination_id' => $id, 'category' => $category, 'mode' => $mode];
            }
            foreach ($channel['mutes'] as $siteId) {
                $mutes[] = ['destination_id' => $id, 'site_id' => $siteId, 'actor_id' => null, 'created_at' => $now];
            }
        }
        foreach ($plan['account_channels'] as $userId => $channel) {
            $id = DB::table('notification_destinations')->insertGetId(['label' => $channel['label'], 'channel' => 'mail', 'locale' => null,
                'enabled' => true, 'config' => '{}', 'recipient_kind' => 'user', 'user_id' => $userId, 'account_mail' => true,
                'created_at' => $now, 'updated_at' => $now]);
            foreach ($channel['cells'] as $category => $mode) {
                $preferences[] = ['destination_id' => $id, 'category' => $category, 'mode' => $mode];
            }
        }
        foreach (array_chunk($preferences, 1000) as $chunk) {
            DB::table('notification_preferences')->insert($chunk);
        }
        foreach (array_chunk($mutes, 1000) as $chunk) {
            DB::table('notification_site_mutes')->insert($chunk);
        }
        foreach ($plan['schedules'] as $schedule) {
            DB::table('notification_schedules')->insert([...$schedule, 'digest_weekdays' => $schedule['digest_weekdays'] === null ? null : json_encode($schedule['digest_weekdays'], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function dropLegacy(): void
    {
        Schema::drop('notification_rule_destination');
        Schema::drop('notification_rules');
        Schema::drop('site_notification_follows');
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['scope_type', 'scope_id', 'notification_types', 'timezone', 'quiet_start', 'quiet_end', 'quiet_weekends',
                'digest_enabled', 'digest_time', 'digest_weekdays']);
        });
        DB::statement('ALTER TABLE notification_destinations ALTER COLUMN recipient_kind SET NOT NULL');
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT notification_destinations_recipient CHECK ((recipient_kind = 'team' AND user_id IS NULL AND client_id IS NULL AND locale IS NOT NULL) OR (recipient_kind = 'user' AND client_id IS NULL AND (user_id IS NOT NULL OR removed_at IS NOT NULL)) OR (recipient_kind = 'client' AND client_id IS NOT NULL AND user_id IS NULL))");
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT notification_destinations_account_mail CHECK (NOT account_mail OR (recipient_kind = 'user' AND channel = 'mail'))");
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT notification_destinations_scope_client CHECK (scope_client_id IS NULL OR recipient_kind = 'team')");
        DB::statement("ALTER TABLE notification_destinations ADD CONSTRAINT notification_destinations_removed CHECK ((removed_at IS NULL) = (removed_reason IS NULL) AND (removed_reason IS NULL OR removed_reason IN ('archived', 'recipient_deleted')))");
        DB::statement('CREATE UNIQUE INDEX notification_destinations_account_mail_user ON notification_destinations (user_id) WHERE account_mail');
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('rule_digest');
            $table->dropForeign(['destination_id']);
            $table->foreign('destination_id')->references('id')->on('notification_destinations')->restrictOnDelete();
        });
    }

    private function backfillDeliveries(): void
    {
        $last = 0;
        do {
            $query = Delivery::query()->with('event.check')->where('id', '>', $last);
            $query->getQuery()->orderBy('id')->limit(1000);
            $batch = $query->get();
            $byCategory = [];
            foreach ($batch as $delivery) {
                $last = $delivery->id;
                $category = NotificationCategory::of($delivery->event ?? throw new LogicException('A delivery must have an event.'));
                if ($category instanceof NotificationCategory) {
                    $byCategory[$category->value][] = $delivery->id;
                }
            }
            foreach ($byCategory as $category => $ids) {
                DB::table('deliveries')->whereIn('id', $ids)->update(['category' => $category]);
            }
        } while ($batch->count() === 1000);
    }
};
