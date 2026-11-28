<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDear\OhDearIdentityConflict;
use Heptapod\Contracts\OhDearDefinitionImporter;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Data\Import\ImportPlanRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\ImportSection;
use Heptapod\Exceptions\InvalidOhDearResponse;
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
    /** Must list the keys of `$settings` in siteSettings, in order; scripts/check-import-notices.php derives notice codes from it. */
    public const array SettingsTypes = ['uptime', 'performance', 'certificate_health', 'dns', 'broken_links', 'application_health', 'domain', 'sitemap', 'lighthouse', 'ports', 'dns_blocklist', 'ai'];

    public function __construct(
        private CheckRegistry $registry,
        private NormaliseSiteUrl $normalise,
        private SiteUrlDuplicates $duplicates,
        private CreateSite $createSite,
        private UpdateSite $updateSite,
        private UpdateCheckConfig $configure,
        private EnableCheck $enable,
        private CreateMaintenancePeriod $createMaintenance,
        private UpdateMaintenancePeriod $updateMaintenance,
        private DeleteMaintenancePeriod $deleteMaintenance,
        private CreateRecurringMaintenancePeriod $createRecurring,
        private UpdateRecurringMaintenancePeriod $updateRecurring,
        private RecurringMaintenanceAttributes $recurringAttributes,
    ) {}

    /**
     * @param  list<ImportSection>  $sections
     * @param  array{clientId: int|null, siteId: int|null}|null  $approved  Null when not running from a saved plan.
     * @param  (\Closure(): void)|null  $beforeWrite  Runs first in the write transaction, after every Oh Dear request; throws to stop.
     */
    public function __invoke(#[\SensitiveParameter] OhDear $sdk, #[\SensitiveParameter] Monitor $monitor, string $timezone, bool $dryRun, array $sections, ?array $approved, ?\Closure $beforeWrite = null): ImportPlanRowData
    {
        if ($monitor->type !== 'http') {
            return $this->skipped($monitor, 'monitor:unsupported', __('import.unsupported_monitor', ['type' => $monitor->type]));
        }
        $site = Site::query()->where('oh_dear_id', $monitor->id)->first();
        // A site whose deletion is pending is neither updated nor imported again; its URL is free once it is gone. A
        // deletion that starts after this read is accepted: what the import writes belongs to the marked site, and the
        // final DeleteSite removes it with the site.
        if ($site !== null && $site->marked_for_deletion_at !== null) {
            return $this->skipped($monitor, 'site:deleting', __('import.notice.site_deleting'));
        }
        $sites = in_array(ImportSection::Sites, $sections, true);
        $importCron = in_array(ImportSection::Cron, $sections, true);
        $importMaintenance = in_array(ImportSection::Maintenance, $sections, true);
        if ($sites) {
            $url = ($this->normalise)($monitor->url);
            $duplicate = $site !== null && $site->url === $monitor->url ? null : $this->duplicates->find($url, $site?->id);
            if ($duplicate instanceof Site) {
                return $duplicate->marked_for_deletion_at !== null
                    ? $this->skipped($monitor, 'site:deleting', __('import.notice.site_deleting'))
                    : $this->skipped($monitor, 'site:exists', $this->duplicates->message($duplicate));
            }
            // UI imports never fall back to No client when an existing site disappeared since the plan.
            if ($site === null && $approved !== null && $approved['clientId'] === null) {
                return $this->skipped($monitor, 'plan:changed', __('import.notice.plan_changed'));
            }
        } elseif ($site === null) {
            return $this->skipped($monitor, 'site:unmatched', __('import.notice.site_unmatched'));
        } elseif ($approved !== null && $site->id !== $approved['siteId']) {
            // A child-only plan approves a specific local target, not a monitor that might match later.
            return $this->skipped($monitor, 'plan:changed', __('import.notice.plan_changed'));
        }
        /** @var list<array{type: string, enabled: bool, settings?: array<string, mixed>}> $checks */
        $checks = $sites || $importCron ? $monitor->checks : [];
        $attributes = ['checks' => [], 'include_check_types_in_report' => []];
        $skipped = $setupRequired = $shortCredentials = $invalid = $invalidTypes = $unavailable = [];
        $inheritsAlertTiming = null;
        if ($sites) {
            [$attributes, $skipped, $setupRequired, $shortCredentials, $invalid, $invalidTypes, $unavailable] = $this->siteSettings($monitor, $checks, $importCron);
            $uptimeSettings = $attributes['uptime_check_settings'] ?? null;
            $inheritsAlertTiming = is_array($uptimeSettings) ? ($uptimeSettings['failed_notification_threshold'] ?? null) === null : null;
        }
        $definitions = [];
        $cronState = $importCron ? (array_column($checks, 'enabled', 'type')[CheckKey::Cron->value] ?? null) : null;
        $cronMapping = $importCron ? $this->registry->importMapping(CheckKey::Cron) : null;
        $cronImporter = $cronMapping?->definitions === null || $this->registry->availability(CheckKey::Cron)->reason !== null ? null : app($cronMapping->definitions);
        if (! $sites && $cronMapping instanceof OhDearImportMapping && $this->registry->availability(CheckKey::Cron)->reason !== null
            && in_array(CheckKey::Cron->value, array_column($checks, 'type'), true)) {
            $unavailable[] = CheckKey::Cron->value;
        }
        if ($cronImporter instanceof OhDearDefinitionImporter) {
            /** @var list<CronCheckDefinition> $remoteDefinitions */
            $remoteDefinitions = $this->decoded(static fn (): array => iterator_to_array($sdk->cronCheckDefinitions($monitor->id), false), 'import.invalid_cron_response');
            foreach ($remoteDefinitions as $definition) {
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
        if ($sites && $site !== null && $invalidTypes !== []) {
            foreach ($site->checks as $existing) {
                if ($existing->enabled && in_array($existing->type->value, $invalidTypes, true)) {
                    $attributes['checks'][] = $existing->type->value;
                }
            }
        }
        /** @var list<RemoteMaintenancePeriod> $maintenance */
        $maintenance = ! $importMaintenance ? [] : $this->decoded(static function () use ($sdk, $monitor, $timezone): array {
            $periods = iterator_to_array($sdk->maintenancePeriods($monitor->id), false);
            foreach ($periods as $period) {
                CarbonImmutable::parse($period->startsAt, $timezone);
                CarbonImmutable::parse($period->endsAt, $timezone);
            }

            return $periods;
        }, 'import.invalid_maintenance_response');
        /** @var list<RemoteRecurringPeriod> $recurring */
        $recurring = $importMaintenance ? $this->recurringMaintenancePeriods($sdk, $monitor->id) : [];
        foreach ($maintenance as $period) {
            $this->createMaintenance->validate($period->name, CarbonImmutable::parse($period->startsAt, $timezone), CarbonImmutable::parse($period->endsAt, $timezone));
        }
        foreach ($recurring as $period) {
            $this->recurringAttributes->validate($this->recurringValues($period, $timezone));
        }
        $coveredIds = $this->coveredIds($site, $maintenance, $timezone);
        $siteId = $site?->id;
        if (! $dryRun) {
            [$coveredIds, $siteId] = DB::transaction(function () use ($site, $monitor, $attributes, $maintenance, $recurring, $timezone, $cronImporter, $definitions, $approved, $sites, $importCron, $cronState, $beforeWrite): array {
                if ($beforeWrite instanceof \Closure) {
                    $beforeWrite();
                }
                if ($sites) {
                    $site = $site === null
                        ? ($this->createSite)($approved['clientId'] ?? Client::query()->where('is_internal', true)->firstOrFail()->id, $attributes)
                        : ($this->updateSite)($site, $attributes, preserveCheck: $importCron ? null : CheckKey::Cron);
                    $site->setAttribute('oh_dear_id', $monitor->id);
                    $site->save();
                }
                if ($cronImporter instanceof OhDearDefinitionImporter) {
                    $cronCheck = $site->checks()->getQuery()->where('type', CheckKey::Cron)->first();
                    if ($cronCheck === null) {
                        // Cron-only import provisions the parent only when definitions need it.
                        $definition = $this->registry->get(CheckKey::Cron)->definition();
                        $cronCheck = $site->checks()->make(['type' => CheckKey::Cron, 'enabled' => false, 'interval_seconds' => $definition->defaultInterval, 'failure_threshold' => $definition->defaultFailureThreshold]);
                        ($this->configure)($cronCheck, []);
                        if ($cronState === true) {
                            ($this->enable)($cronCheck);
                        }
                    }
                    $cronImporter($cronCheck->id, $definitions);
                }
                foreach ($recurring as $remote) {
                    $period = RecurringMaintenancePeriod::query()->where('oh_dear_id', $remote->id)->first();
                    $values = $this->recurringValues($remote, $timezone);
                    if ($period !== null && $period->site_id !== $site->id) {
                        throw new OhDearIdentityConflict('Oh Dear recurring identity belongs to another site.');
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
        $details = [];
        $cronCount = $importCron ? count($definitions) : null;
        $maintenanceCounts = $importMaintenance ? ['one_off' => count($maintenance), 'recurring' => count($recurring)] : null;
        if ($cronCount !== null) {
            $details[] = __('import.details.cron', ['cron' => $cronCount]);
        }
        if ($maintenanceCounts !== null) {
            $details[] = __('import.details.maintenance', [...$maintenanceCounts, 'one_off' => $maintenanceCounts['one_off'] - count($coveredIds)]);
        }
        if ($coveredIds !== []) {
            $details[] = __('import.covered', ['count' => count($coveredIds)]);
        }
        $cronDisabledWithoutDefinitions = in_array(CheckKey::Cron->value, $setupRequired, true) && $cronState === false;
        $unavailable = array_values(array_unique($unavailable));
        $notices = OhDearImportNotices::keys(array_values(array_unique($skipped)), $setupRequired, $cronDisabledWithoutDefinitions, array_values(array_unique($invalidTypes)), $shortCredentials,
            $sites && ($monitor->domainCheckSettings['not_supported_reason'] ?? null) !== null, $unavailable);
        $notImported = [];
        if ($unavailable !== []) {
            $notImported[] = __('import.unavailable', ['checks' => implode(', ', array_map(fn (string $type): string => $type.' ('.__((string) $this->registry->availability(CheckKey::from($type))->reason).')', $unavailable))]);
        }
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
            $notices, implode('; ', [...$details, ...$notImported]), $siteId, $siteId === null ? null : Site::query()->with('client')->findOrFail($siteId)->client?->displayName(),
            $inheritsAlertTiming, $maintenanceCounts, $cronCount === null ? null : ($cronImporter instanceof OhDearDefinitionImporter ? $cronCount : 0));
    }

    /**
     * @param  list<array{type: string, enabled: bool, settings?: array<string, mixed>}>  $checks
     * @return array{array{checks: list<string>, include_check_types_in_report: list<string>, ...<string, mixed>}, list<string>, list<string>, list<string>, list<string>, list<string>, list<string>}
     */
    private function siteSettings(#[\SensitiveParameter] Monitor $monitor, array $checks, bool $importCron): array
    {
        $attributes = ['url' => $monitor->url, 'friendly_name' => $monitor->label, 'group_name' => $monitor->groupName,
            'description' => $monitor->description, 'notes' => $monitor->notes, 'tags' => $monitor->tags,
            'crawler_headers' => $monitor->crawlerHeaders, 'send_report_to_emails' => $monitor->sendReportToEmails, 'checks' => []];
        $skipped = [];
        $setupRequired = [];
        $shortCredentials = [];
        $invalid = [];
        $invalidTypes = [];
        $unavailable = [];
        $settings = [
            'uptime' => $monitor->uptimeCheckSettings, 'performance' => $monitor->performanceCheckSettings,
            'certificate_health' => $monitor->certificateHealthCheckSettings, 'dns' => $monitor->dnsCheckSettings,
            'broken_links' => $monitor->brokenLinksCheckSettings, 'application_health' => $monitor->applicationHealthCheckSettings,
            'domain' => $monitor->domainCheckSettings, 'sitemap' => $monitor->sitemapCheckSettings, 'lighthouse' => $monitor->lighthouseCheckSettings,
            'ports' => $monitor->portsCheckSettings ?? [], 'dns_blocklist' => $monitor->dnsBlocklistCheckSettings ?? [], 'ai' => $monitor->aiCheckSettings ?? [],
        ];
        foreach ($checks as $check) {
            if (! $importCron && $check['type'] === CheckKey::Cron->value) {
                continue;
            }
            if ($check['type'] === CheckKey::BrokenLinks->value) {
                // Some crawl settings exist only on the nested check; root settings and site headers are authoritative.
                $settings['broken_links'] = array_replace(Arr::except($check['settings'] ?? [], ['crawler_headers']), $settings['broken_links']);
            }
            $key = CheckKey::tryFrom($check['type']);
            if ($key === null || ! $this->registry->importMapping($key) instanceof OhDearImportMapping) {
                $skipped[] = $check['type'];
            } elseif ($this->registry->availability($key)->reason !== null) {
                $unavailable[] = $key->value;
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
            // This instance cannot run the check (e.g. no browser service), so neither it nor its settings are imported.
            if ($this->registry->availability($key)->reason !== null) {
                if ($values !== []) {
                    $unavailable[] = $type;
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
            $config = Arr::except($mapped, OhDearImportMapping::ScheduleSettings);
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

        return [$attributes, $skipped, $setupRequired, $shortCredentials, $invalid, $invalidTypes, $unavailable];
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
            throw new InvalidOhDearResponse('import.invalid_recurring_response');
        }
        if ($shape instanceof \stdClass && isset($shape->data) && is_array($shape->data)) {
            $items = $response->json('data');
        } elseif (is_array($shape)) {
            $items = $response->json();
        } else {
            throw new InvalidOhDearResponse('import.invalid_recurring_response');
        }
        if (! is_array($items) || ! array_is_list($items) || array_any($items, static fn (mixed $item): bool => ! is_array($item) || array_is_list($item))) {
            throw new InvalidOhDearResponse('import.invalid_recurring_response');
        }

        /** @var list<RemoteRecurringPeriod> $periods */
        $periods = $this->decoded(static fn (): array => RemoteRecurringPeriod::collect($items), 'import.invalid_recurring_response');

        return $periods;
    }

    /**
     * Reads source data through the data objects of the SDK. A row they cannot decode (missing fields, wrong types) is a bad Oh Dear
     * answer; a TypeError raised anywhere else is a local fault and must stop the import.
     *
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T
     */
    private function decoded(\Closure $read, string $causeKey): mixed
    {
        try {
            return $read();
        } catch (\TypeError|\ErrorException $exception) {
            if (! str_contains(str_replace('\\', '/', $exception->getFile()), '/ohdear-php-sdk/src/Dto/')) {
                throw $exception;
            }
            throw new InvalidOhDearResponse($causeKey, $exception->getCode(), $exception);
        } catch (InvalidFormatException $exception) {
            // A read parses only source dates, so an unparsable one is a bad Oh Dear answer.
            throw new InvalidOhDearResponse($causeKey, $exception->getCode(), $exception);
        }
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
                throw new OhDearIdentityConflict('Oh Dear maintenance identity belongs to another site.');
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
