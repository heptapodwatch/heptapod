<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearDefinitionImporter;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Exceptions\InvalidOhDearRecurringResponse;
use Heptapod\Maintenance\RecurringMaintenanceAttributes;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\OhDearImportCoverage;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OhDear\PhpSdk\Dto\CronCheckDefinition;
use OhDear\PhpSdk\Dto\MaintenancePeriod as RemoteMaintenancePeriod;
use OhDear\PhpSdk\Dto\Monitor;
use OhDear\PhpSdk\Dto\RecurringMaintenancePeriod as RemoteRecurringPeriod;
use OhDear\PhpSdk\OhDear;
use OhDear\PhpSdk\Requests\RecurringMaintenancePeriods\GetRecurringMaintenancePeriodsRequest;

final readonly class ImportOhDearMonitor
{
    /** Must list the keys of `$settings` in __invoke, in order; scripts/check-import-notices.php derives notice codes from it. */
    public const array SettingsTypes = ['uptime', 'performance', 'certificate_health', 'dns', 'broken_links', 'application_health', 'domain', 'sitemap', 'lighthouse', 'ports', 'dns_blocklist', 'ai'];

    public function __construct(
        private CheckRegistry $registry,
        private NormaliseSiteUrl $normalise,
        private SiteUrlDuplicates $duplicates,
        private CreateSite $createSite,
        private UpdateSite $updateSite,
        private UpdateCheckConfig $configure,
        private CreateMaintenancePeriod $createMaintenance,
        private UpdateMaintenancePeriod $updateMaintenance,
        private DeleteMaintenancePeriod $deleteMaintenance,
        private CreateRecurringMaintenancePeriod $createRecurring,
        private UpdateRecurringMaintenancePeriod $updateRecurring,
        private RecurringMaintenanceAttributes $recurringAttributes,
    ) {}

    /** @param int|null $clientId Client for a newly created site (default Internal); existing sites keep their client. */
    public function __invoke(#[\SensitiveParameter] OhDear $sdk, #[\SensitiveParameter] Monitor $monitor, string $timezone, bool $dryRun, ?int $clientId = null, bool $requireClient = false): ImportPlanRowData
    {
        if ($monitor->type !== 'http') {
            return $this->skipped($monitor, 'monitor:unsupported', __('import.unsupported_monitor', ['type' => $monitor->type]));
        }
        $site = Site::query()->where('oh_dear_id', $monitor->id)->first();
        $url = ($this->normalise)($monitor->url);
        $duplicate = $site !== null && $site->url === $monitor->url ? null : $this->duplicates->find($url, $site?->id);
        if ($duplicate !== null) {
            return $this->skipped($monitor, 'site:exists', $this->duplicates->message($duplicate));
        }
        // UI imports never fall back to Internal: a site that would be new but has no approved client changed since the plan.
        if ($requireClient && $site === null && $clientId === null && ! $dryRun) {
            return $this->skipped($monitor, 'plan:changed', 'Changed since the plan; show the plan again to import it.');
        }
        $attributes = ['url' => $monitor->url, 'friendly_name' => $monitor->label, 'group_name' => $monitor->groupName,
            'description' => $monitor->description, 'notes' => $monitor->notes, 'tags' => $monitor->tags,
            'crawler_headers' => $monitor->crawlerHeaders, 'send_report_to_emails' => $monitor->sendReportToEmails, 'checks' => []];
        $skipped = [];
        $setupRequired = [];
        $shortCredentials = [];
        $invalid = [];
        $invalidTypes = [];
        $settings = [
            'uptime' => $monitor->uptimeCheckSettings, 'performance' => $monitor->performanceCheckSettings,
            'certificate_health' => $monitor->certificateHealthCheckSettings, 'dns' => $monitor->dnsCheckSettings,
            'broken_links' => $monitor->brokenLinksCheckSettings, 'application_health' => $monitor->applicationHealthCheckSettings,
            'domain' => $monitor->domainCheckSettings, 'sitemap' => $monitor->sitemapCheckSettings, 'lighthouse' => $monitor->lighthouseCheckSettings,
            'ports' => $monitor->portsCheckSettings ?? [], 'dns_blocklist' => $monitor->dnsBlocklistCheckSettings ?? [], 'ai' => $monitor->aiCheckSettings ?? [],
        ];
        /** @var list<array{type: string, enabled: bool, settings?: array<string, mixed>}> $checks */
        $checks = $monitor->checks;
        foreach ($checks as $check) {
            if ($check['type'] === CheckKey::BrokenLinks->value) {
                // Some crawl settings exist only on the nested check; root settings and site headers are authoritative.
                $settings['broken_links'] = array_replace(Arr::except($check['settings'] ?? [], ['crawler_headers']), $settings['broken_links']);
            }
            $key = CheckKey::tryFrom($check['type']);
            if ($key === null || ! $this->registry->importMapping($key) instanceof OhDearImportMapping) {
                $skipped[] = $check['type'];
            } elseif ($check['enabled']) {
                $attributes['checks'][] = $key->value;
            }
        }
        foreach ($settings as $type => $values) {
            /** @var array<string, mixed> $values */
            $key = CheckKey::tryFrom($type);
            $mapping = $key === null ? null : $this->registry->importMapping($key);
            if (! $mapping instanceof OhDearImportMapping) {
                if ($values !== []) {
                    $skipped[] = $type.'_check_settings';
                }

                continue;
            }
            foreach (array_diff(array_keys($values), $mapping->settings) as $field) {
                $skipped[] = $type.'_check_settings.'.$field;
            }
            if ($values === [] && ! in_array($type, array_column($checks, 'type'), true)) {
                continue;
            }
            $definition = $this->registry->get($key)->definition();
            $defaults = $definition->configSchema->defaults();
            $multiplier = match (true) {
                $definition->defaultInterval % 86400 === 0 => 86400,
                $definition->defaultInterval > 3600 && $definition->defaultInterval % 3600 === 0 => 3600,
                default => 60,
            };
            $defaults += ['frequency_value' => intdiv($definition->defaultInterval, $multiplier),
                'frequency_unit' => match ($multiplier) {
                    86400 => 'day', 3600 => 'hour', default => 'minute'
                },
                'failed_notification_threshold' => $definition->defaultFailureThreshold];
            /** @var array<string, mixed> $mapped */
            $mapped = array_replace(Arr::only($defaults, $mapping->settings), array_filter(Arr::only($values, $mapping->settings), static fn (mixed $value): bool => $value !== null));
            if ($key === CheckKey::BrokenLinks && is_array($mapped['whitelisted_urls'] ?? null) && array_is_list($mapped['whitelisted_urls'])) {
                $validWhitelist = [];
                foreach ($mapped['whitelisted_urls'] as $index => $entry) {
                    try {
                        $validated = $definition->configSchema->validateConfig(['whitelisted_urls' => [$entry]]);
                        if (($validated->public['whitelisted_urls'] ?? []) === []) {
                            $skipped[] = 'broken_links_check_settings.whitelisted_urls.'.$index;

                            continue;
                        }
                    } catch (ValidationException) {
                        $skipped[] = 'broken_links_check_settings.whitelisted_urls.'.$index;

                        continue;
                    }
                    $validWhitelist[] = $entry;
                }
                $mapped['whitelisted_urls'] = $validWhitelist;
            }
            if ($key === CheckKey::Sitemap && isset($mapped['path']) && is_string($mapped['path']) && preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_./-]*$~D', $mapped['path']) === 1 && ! in_array('..', explode('/', $mapped['path']), true)) {
                $mapped['path'] = '/'.$mapped['path'];
            }
            if (array_key_exists('frequency_value', $mapped)) {
                if (! isset($values['frequency_value'])) {
                    $mapped['frequency_value'] = $defaults['frequency_value'];
                    $mapped['frequency_unit'] = $defaults['frequency_unit'];
                } elseif (! isset($values['frequency_unit'])) {
                    $mapped['frequency_unit'] = 'minute';
                }
            }
            foreach (['look_for_string', 'absent_string'] as $field) {
                if (($mapped[$field] ?? null) === '') {
                    $mapped[$field] = $defaults[$field];
                }
            }
            if (($mapped['failed_notification_threshold'] ?? null) === 0) {
                $mapped['failed_notification_threshold'] = 1;
            }
            $threshold = $mapped['failed_notification_threshold'] ?? null;
            if ($key === CheckKey::Uptime && ($threshold === 2 || (is_string($threshold) && trim($threshold) === ''))) {
                $mapped['failed_notification_threshold'] = null;
            }
            $fields = [];
            if ($key === CheckKey::Sitemap && is_string($mapped['path'] ?? null) && in_array('..', explode('/', $mapped['path']), true)) {
                $fields[] = 'path';
            }
            try {
                $this->configure->validateSchedule($mapped, $key === CheckKey::Uptime);
            } catch (ValidationException $exception) {
                $fields = array_keys($exception->errors());
            }
            /** @var array<string, mixed> $config */
            $config = Arr::except($mapped, ['frequency_value', 'frequency_unit', 'failed_notification_threshold']);
            try {
                $definition->configSchema->validateConfig($config);
            } catch (ValidationException $exception) {
                /** @var array<string, array<string, list<mixed>>> $failedRules */
                $failedRules = $exception->validator->failed();
                foreach ($failedRules as $field => $rules) {
                    if (isset($rules[SecretMinimum::class])) {
                        $shortCredentials[] = $type.'_check_settings.'.substr($field, strlen('values.'));
                    }
                }
                if ($this->missingRequiredSettings($exception, $config)) {
                    $setupRequired[] = $type;
                } else {
                    foreach (array_keys($exception->errors()) as $field) {
                        $fields[] = str_starts_with($field, 'values.') ? substr($field, strlen('values.')) : $field;
                    }
                }
            }
            if ($fields !== []) {
                $invalidTypes[] = $type;
                foreach ($fields as $field) {
                    $invalid[] = $type.'_check_settings.'.$field;
                }
            }
            if ($fields !== [] || in_array($type, $setupRequired, true)) {
                continue;
            }
            $attributes[$type.'_check_settings'] = $mapped;
        }
        $attributes['include_check_types_in_report'] = [];
        foreach ($monitor->includeCheckTypesInReport as $type) {
            $key = is_string($type) ? CheckKey::tryFrom($type) : null;
            if ($key !== null && $this->registry->importMapping($key) instanceof OhDearImportMapping) {
                $attributes['include_check_types_in_report'][] = $type;
            } else {
                $skipped[] = 'include_check_types_in_report';
            }
        }
        $definitions = [];
        $cronMapping = $this->registry->importMapping(CheckKey::Cron);
        $cronImporter = $cronMapping?->definitions === null ? null : app($cronMapping->definitions);
        if ($cronImporter instanceof OhDearDefinitionImporter) {
            foreach ($sdk->cronCheckDefinitions($monitor->id) as $definition) {
                $definitions[] = $this->cronAttributes($definition);
            }
            try {
                $cronImporter->validate($definitions);
            } catch (ValidationException $exception) {
                $invalidTypes[] = CheckKey::Cron->value;
                foreach (array_keys($exception->errors()) as $field) {
                    $invalid[] = 'cron_check_settings.'.$field;
                }
                $cronImporter = null;
            }
            if ($definitions === []) {
                $cronImporter = null;
                if (in_array(CheckKey::Cron->value, array_column($checks, 'type'), true)) {
                    $setupRequired[] = CheckKey::Cron->value;
                }
            } elseif ($cronImporter instanceof OhDearDefinitionImporter) {
                $attributes[CheckKey::Cron->value.'_check_settings'] = [];
            }
        }
        $attributes['checks'] = array_values(array_diff($attributes['checks'], $setupRequired, $invalidTypes));
        $attributes['include_check_types_in_report'] = array_values(array_diff($attributes['include_check_types_in_report'], $setupRequired, $invalidTypes));
        // A rejected source check must not overwrite a previously valid local configuration/state.
        if ($site !== null && $invalidTypes !== []) {
            foreach ($site->checks as $existing) {
                if ($existing->enabled && in_array($existing->type->value, $invalidTypes, true)) {
                    $attributes['checks'][] = $existing->type->value;
                }
            }
        }
        /** @var list<RemoteMaintenancePeriod> $maintenance */
        $maintenance = iterator_to_array($sdk->maintenancePeriods($monitor->id), false);
        /** @var list<RemoteRecurringPeriod> $recurring */
        $recurring = $this->recurringMaintenancePeriods($sdk, $monitor->id);
        foreach ($maintenance as $period) {
            $this->createMaintenance->validate($period->name, CarbonImmutable::parse($period->startsAt, $timezone), CarbonImmutable::parse($period->endsAt, $timezone));
        }
        foreach ($recurring as $period) {
            $this->recurringAttributes->validate($this->recurringValues($period, $timezone));
        }
        $coveredIds = $this->coveredIds($site, $maintenance, $timezone);
        $siteId = $site?->id;
        if (! $dryRun) {
            [$coveredIds, $siteId] = DB::transaction(function () use ($site, $monitor, $attributes, $maintenance, $recurring, $timezone, $cronImporter, $definitions, $clientId): array {
                $site = $site === null ? ($this->createSite)($clientId ?? Client::query()->where('is_internal', true)->firstOrFail()->id, $attributes) : ($this->updateSite)($site, $attributes);
                $site->setAttribute('oh_dear_id', $monitor->id);
                $site->save();
                if ($cronImporter instanceof OhDearDefinitionImporter) {
                    $cronCheck = $site->checks()->getQuery()->where('type', CheckKey::Cron)->firstOrFail();
                    $cronImporter($cronCheck->id, $definitions);
                }
                foreach ($recurring as $remote) {
                    $period = RecurringMaintenancePeriod::query()->where('oh_dear_id', $remote->id)->first();
                    $values = $this->recurringValues($remote, $timezone);
                    if ($period !== null && $period->site_id !== $site->id) {
                        throw new \LogicException('Oh Dear recurring identity belongs to another site.');
                    }
                    if ($period === null) {
                        $period = ($this->createRecurring)($site, $values);
                    } else {
                        $candidate = clone $period;
                        $candidate->fill($values);
                        if ($candidate->isDirty()) {
                            $period = ($this->updateRecurring)($period, $values);
                        }
                    }
                    $period->setAttribute('oh_dear_id', $remote->id);
                    $period->save();
                }
                $coveredIds = $this->coveredIds($site, $maintenance, $timezone);
                foreach ($maintenance as $remote) {
                    $period = MaintenancePeriod::query()->where('oh_dear_id', $remote->id)->first();
                    $coverage = OhDearImportCoverage::query()->where('oh_dear_id', $remote->id)->first();
                    $starts = CarbonImmutable::parse($remote->startsAt, $timezone)->utc();
                    $ends = CarbonImmutable::parse($remote->endsAt, $timezone)->utc();
                    if (isset($coveredIds[$remote->id])) {
                        if ($period !== null && $ends >= CarbonImmutable::now()) {
                            ($this->deleteMaintenance)($period);
                        }
                        OhDearImportCoverage::query()->updateOrCreate(['oh_dear_id' => $remote->id], ['site_id' => $site->id, 'starts_at' => $starts, 'ends_at' => $ends]);

                        continue;
                    }
                    $period = $period === null ? ($this->createMaintenance)($site, $remote->name, $starts, $ends) : ($this->updateMaintenance)($period, $remote->name, $starts, $ends);
                    $period->setAttribute('oh_dear_id', $remote->id);
                    $period->save();
                    if ($coverage !== null) {
                        $coverage->update(['starts_at' => $starts, 'ends_at' => $ends]);
                    }
                }

                return [$coveredIds, $site->id];
            });
        }
        $details = [__('import.children', ['cron' => count($definitions), 'maintenance' => count($maintenance) - count($coveredIds), 'recurring' => count($recurring)])];
        if ($coveredIds !== []) {
            $details[] = __('import.covered', ['count' => count($coveredIds)]);
        }
        $cronDisabledWithoutDefinitions = in_array(CheckKey::Cron->value, $setupRequired, true) && (array_column($checks, 'enabled', 'type')[CheckKey::Cron->value] ?? null) === false;
        $notices = OhDearImportNotices::keys(array_values(array_unique($skipped)), array_values($setupRequired), $cronDisabledWithoutDefinitions, array_values(array_unique($invalidTypes)), $shortCredentials,
            ($monitor->domainCheckSettings['not_supported_reason'] ?? null) !== null);
        $notImported = [];
        if ($setupRequired !== []) {
            $notImported[] = __('import.setup_required', ['checks' => implode(', ', $setupRequired)]);
        }
        if ($cronDisabledWithoutDefinitions) {
            $notImported[] = __('import.disabled_cron_without_definitions');
        }
        if ($skipped !== []) {
            $notImported[] = __('import.unsupported', ['fields' => implode(', ', array_unique($skipped))]);
        }
        if ($invalid !== []) {
            $notImported[] = __('import.invalid', ['fields' => implode(', ', $invalid)]);
        }
        if ($shortCredentials !== []) {
            $notImported[] = __('import.short_credentials', ['fields' => implode(', ', $shortCredentials)]);
        }
        $enabled = array_values(array_unique($attributes['checks']));
        $configured = [];
        foreach (array_keys($attributes) as $attribute) {
            if (str_ends_with($attribute, '_check_settings')) {
                $configured[] = substr($attribute, 0, -strlen('_check_settings'));
            }
        }

        return new ImportPlanRowData((string) $monitor->id, $monitor->url, $monitor->label, $monitor->teamId, array_values(array_filter($monitor->tags, is_string(...))),
            $invalid !== [] ? ImportOperation::Partial : ($site === null ? ImportOperation::Create : ImportOperation::Update), $enabled, array_values(array_diff($configured, $enabled)),
            $notices, implode('; ', [...$details, ...$notImported]), $siteId, $siteId === null ? null : Site::query()->with('client')->findOrFail($siteId)->client?->name,
            isset($attributes['uptime_check_settings']) ? ($attributes['uptime_check_settings']['failed_notification_threshold'] ?? null) === null : null);
    }

    private function skipped(Monitor $monitor, string $code, string $reason): ImportPlanRowData
    {
        return new ImportPlanRowData((string) $monitor->id, $monitor->url, $monitor->label, $monitor->teamId, array_values(array_filter($monitor->tags, is_string(...))), ImportOperation::Skipped, [], [], [OhDearImportNotices::key($code)], $reason);
    }

    /** @return list<RemoteRecurringPeriod> */
    private function recurringMaintenancePeriods(#[\SensitiveParameter] OhDear $sdk, int $monitorId): array
    {
        // The API can return a bare list or a data envelope; the SDK expects only the envelope.
        // TODO: remove this adapter when the SDK accepts both response shapes.
        $response = $sdk->send(new GetRecurringMaintenancePeriodsRequest($monitorId));
        try {
            $shape = $response->object();
        } catch (\JsonException) {
            throw new InvalidOhDearRecurringResponse(__('import.invalid_recurring_response'));
        }
        if ($shape instanceof \stdClass && isset($shape->data) && is_array($shape->data)) {
            $items = $response->json('data');
        } elseif (is_array($shape)) {
            $items = $response->json();
        } else {
            throw new InvalidOhDearRecurringResponse(__('import.invalid_recurring_response'));
        }
        if (! is_array($items) || ! array_is_list($items) || array_any($items, static fn (mixed $item): bool => ! is_array($item) || array_is_list($item))) {
            throw new InvalidOhDearRecurringResponse(__('import.invalid_recurring_response'));
        }

        /** @var list<RemoteRecurringPeriod> $periods */
        $periods = RemoteRecurringPeriod::collect($items);

        return $periods;
    }

    /** @param array<string, mixed> $config */
    private function missingRequiredSettings(ValidationException $exception, #[\SensitiveParameter] array $config): bool
    {
        // Only absent root settings are setup work; malformed values or nested objects must fail.
        /** @var array<string, array<string, list<mixed>>> $failures */
        $failures = $exception->validator->failed();
        foreach ($failures as $path => $rules) {
            $field = substr($path, strlen('values.'));
            $value = $config[$field] ?? null;
            if (array_keys($rules) !== ['Required'] || str_contains($field, '.') || ($value !== null && (! is_string($value) || trim($value) !== ''))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<RemoteMaintenancePeriod> $maintenance
     * @return array<int, true>
     */
    private function coveredIds(?Site $site, array $maintenance, string $timezone): array
    {
        $coveredIds = [];
        $now = CarbonImmutable::now();
        foreach ($maintenance as $remote) {
            $persisted = MaintenancePeriod::query()->where('oh_dear_id', $remote->id)->first();
            $coverage = OhDearImportCoverage::query()->where('oh_dear_id', $remote->id)->first();
            if (($persisted !== null && $persisted->site_id !== $site?->id) || ($coverage !== null && $coverage->site_id !== $site?->id)) {
                throw new \LogicException('Oh Dear maintenance identity belongs to another site.');
            }
            $start = CarbonImmutable::parse($remote->startsAt, $timezone)->utc();
            $end = CarbonImmutable::parse($remote->endsAt, $timezone)->utc();
            if ($end < $now) {
                if ($persisted === null && $coverage !== null && $start->equalTo($coverage->starts_at) && $end->equalTo($coverage->ends_at)) {
                    $coveredIds[$remote->id] = true;
                }

                continue;
            }
            if ($site instanceof Site && MaintenancePeriod::query()->getQuery()->where('site_id', $site->id)
                ->whereNotNull('recurring_maintenance_period_id')
                ->where('starts_at', $start->format('Y-m-d H:i:s.uP'))
                ->where('ends_at', $end->format('Y-m-d H:i:s.uP'))->exists()) {
                $coveredIds[$remote->id] = true;
            }
        }

        return $coveredIds;
    }

    /** @return array<string, mixed> */
    private function cronAttributes(CronCheckDefinition $definition): array
    {
        return ['id' => $definition->id, 'name' => $definition->name, 'type' => $definition->type, 'description' => $definition->description,
            'frequency_in_minutes' => $definition->frequencyInMinutes, 'grace_time_in_minutes' => $definition->graceTimeInMinutes,
            'cron_expression' => $definition->cronExpression, 'server_timezone' => $definition->serverTimezone];
    }

    /** @return array<string, mixed> */
    private function recurringValues(RemoteRecurringPeriod $period, string $timezone): array
    {
        return ['name' => $period->name, 'recurrence_type' => $period->recurrenceType, 'days_of_week' => $period->daysOfWeek,
            'day_of_month' => $period->dayOfMonth, 'start_time' => $period->startTime, 'end_time' => $period->endTime, 'timezone' => $timezone];
    }
}
