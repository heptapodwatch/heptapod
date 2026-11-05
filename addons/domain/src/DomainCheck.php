<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Klkvsk\Whoeasy\Parser\Rdap\RdapParser;
use Klkvsk\Whoeasy\Parser\Whois\WhoisParser;
use Klkvsk\Whoeasy\Registry\QueryType;
use Klkvsk\Whoeasy\Result\Info\DomainInfo;
use Klkvsk\Whoeasy\Result\Info\Field\Nameserver;
use Pdp\Domain;
use Spatie\Rdap\Responses\DomainResponse;

final readonly class DomainCheck implements CheckDetailProvider, CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private DomainRegistrationLookup $lookup, private DomainPersistence $store, private PublicSuffixList $suffixes) {}

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?DomainSnapshot
    {
        $latest = $this->store->latest($checkId);

        return $latest === null ? null : DomainSnapshot::from($latest['snapshot']);
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $changes = [];
        foreach (['domain.expiry_changed' => 'Domain expiry changed', 'domain.registration_changed' => 'Domain registration changed'] as $type => $key) {
            if ($run->eventPayload($type) !== null) {
                $changes[] = new CheckRunPresentationPartData($key);
            }
        }
        if ($changes !== [] && $run->result === RunResult::Succeeded) {
            return new CheckRunPresentationData($changes);
        }
        if ($run->metrics === null) {
            return in_array($run->result, [RunResult::Warning, RunResult::Failed], true) && $changes !== []
                ? new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams)], changes: $changes) : null;
        }
        $this->definition()->metricSchema->validateValues($run->metrics);
        $metrics = DomainMetrics::from($run->metrics);
        $key = match ($run->summary) {
            'domain.valid' => 'checks.presentation.domain_valid_until',
            'domain.expires_soon' => 'checks.presentation.domain_expires',
            'domain.expired' => 'checks.presentation.domain_expired',
            default => null,
        };

        $part = $key === null || $metrics->expires_at === null ? null : new CheckRunPresentationPartData($key, ['date' => $metrics->expires_at]);
        if (in_array($run->result, [RunResult::Warning, RunResult::Failed], true) && $changes !== []) {
            return new CheckRunPresentationData([$part ?? new CheckRunPresentationPartData($run->summary, $run->summaryParams)], changes: $changes);
        }

        return $part instanceof CheckRunPresentationPartData ? new CheckRunPresentationData([$part]) : null;
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'domain.mail_event.check.failing',
            'check.warning' => 'domain.mail_event.check.warning',
            'check.recovered' => 'domain.mail_event.check.recovered',
            'domain.expiry_changed' => 'domain.mail_event.domain.expiry_changed',
            'domain.registration_changed' => 'domain.mail_event.domain.registration_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'domain.expiry_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Changed, 'succeeded' => MailTone::Changed],
            'domain.registration_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Changed, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $evidence = $run->observationEvidence();
        if ($evidence === []) {
            return [];
        }
        $snapshot = DomainSnapshot::from($evidence);
        $expiry = __('domain.mail_unpublished', locale: $locale);
        if ($snapshot->expires_at !== null) {
            $date = CarbonImmutable::parse($snapshot->expires_at)->setTimezone($timezone);
            $date->locale($locale);
            $comparisonTime = $snapshot->date_precision === 'day' ? $observedAt->startOfDay() : $observedAt;
            $days = (int) $comparisonTime->diffInDays($date);
            $expiry = $date->isoFormat('ll').' · '.trans_choice('domain.mail_days', $days, ['days' => $days], $locale);
        }

        $sourceKey = match ($snapshot->source) {
            'rdap' => 'domain.mail_source.rdap', 'whois' => 'domain.mail_source.whois',
            default => throw new \LogicException('Unknown retained domain source.'),
        };
        $precisionKey = match ($snapshot->date_precision) {
            'exact' => 'domain.mail_precision.exact', 'day' => 'domain.mail_precision.day',
            default => throw new \LogicException('Unknown retained domain precision.'),
        };
        $source = __($sourceKey, locale: $locale);
        $precision = __($precisionKey, locale: $locale);
        if (! is_string($source) || $source === $sourceKey || ! is_string($precision) || $precision === $precisionKey) {
            throw new \LogicException('Missing retained domain source or precision label.');
        }

        return [new FactsBlock(__('domain.mail_registration', locale: $locale), [
            ['label' => __('domain.mail_domain', locale: $locale), 'value' => new Cell($snapshot->domain, mono: true)],
            ['label' => __('domain.mail_expires', locale: $locale), 'value' => new Cell($expiry, tone: $run->result === RunResult::Failed ? MailTone::Failed : ($run->result === RunResult::Warning ? MailTone::Warning : null))],
            ['label' => __('domain.mail_registrar', locale: $locale), 'value' => new Cell($snapshot->registrar ?? __('domain.mail_unpublished', locale: $locale))],
            ['label' => __('domain.mail_source', locale: $locale), 'value' => new Cell($source.' · '.$precision)],
        ])];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('domain.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(
            CheckKey::Domain, 'checks.domain.label', 'globe',
            'checks-fast', 120, 86400, 1, ExecutionMode::Poll,
            new Schema(['expires_soon_threshold_in_days' => new Field(FieldType::Integer, ['sometimes', 'min:0'], presentation: new FieldPresentation('domain.threshold'), default: 30)]),
            new Schema([
                'expires_at' => new Field(FieldType::String, ['present', 'nullable', 'date']),
                'not_supported_reason' => new Field(FieldType::String, ['present', 'nullable', 'in:registry_does_not_publish,lookup_unavailable']),
            ]),
            emittedEvents: ['domain.expiry_changed', 'domain.registration_changed'],
            detailDataClass: DomainSnapshot::class,
        );
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        $defaults = $this->definition()->configSchema->defaults();
        $host = parse_url($context->url, PHP_URL_HOST);
        if (! is_string($host)) {
            throw new \InvalidArgumentException('Domain check requires a URL hostname.');
        }
        $resolved = $this->suffixes->rules()->getICANNDomain(Domain::fromIDNA2008(strtolower(rtrim($host, '.')))->toAscii());
        $domain = $resolved->registrableDomain()->toString();
        $tld = $resolved->suffix()->toString();
        if ($domain === '') {
            throw new \InvalidArgumentException('Domain check requires a registrable domain.');
        }
        $secrets = [...Arr::flatten($this->definition()->configSchema->validateConfig($context->config)->secrets()), ...array_column($context->crawlerHeaders, 'value')];
        $secrets = array_values(array_filter($secrets, static fn (mixed $secret): bool => is_string($secret) && $secret !== ''));
        $this->assertSecretFree($domain, $secrets);
        try {
            $response = $this->lookup->fetch($domain, $context);
        } catch (HttpWhoisUnsupported|FetchException $exception) {
            return $this->unavailable($domain, $context, $exception instanceof HttpWhoisUnsupported ? 'whois' : 'rdap', '',
                error: new CheckError($exception::class, $exception->getMessage()),
                failureKind: $exception instanceof FetchException ? $exception->failure->kind : null,
                policyFailure: $exception instanceof FetchException ? $exception->failure : null);
        }
        $this->assertSecretFree([$response->raw, $response->server], $secrets);
        $rdap = is_array($response->raw) ? new DomainResponse($response->raw) : null;
        $info = is_array($response->raw)
            ? (new RdapParser)->parse($response->raw)
            : (new WhoisParser)->parse($response->raw, $response->server, QueryType::Domain);
        if (! $info instanceof DomainInfo || strtolower(rtrim($info->name ?? '', '.')) !== $domain) {
            return $this->unavailable($domain, $context, $response->source->value, $response->server);
        }
        $expires = $rdap instanceof DomainResponse ? $this->rdapDate($rdap, 'expiration') : $this->whoisDate($info->expiresDate);
        $flags = DomainStatuses::normalize($info->status);
        $nameservers = array_values(array_unique(array_map(static fn (Nameserver $ns): string => strtolower(rtrim($ns->hostname, '.')), $info->nameservers)));
        sort($nameservers);
        $reason = $expires === null ? (in_array($tld, Config::array('domain.registry_does_not_publish'), true) ? 'registry_does_not_publish' : 'lookup_unavailable') : null;
        $snapshot = new DomainSnapshot($domain, $info->registrar?->name, $expires,
            $rdap instanceof DomainResponse ? $this->rdapDate($rdap, 'registration') : $this->whoisDate($info->createdDate),
            $rdap instanceof DomainResponse ? $this->rdapDate($rdap, 'last changed') : $this->whoisDate($info->updatedDate),
            $rdap instanceof DomainResponse ? $this->rdapDate($rdap, 'last update of RDAP database') : null, $context->startedAt->toIso8601String(),
            $nameservers, $flags, is_array($response->raw) ? $response->raw : null, $rdap instanceof DomainResponse ? 'exact' : 'day', $response->source->value, $response->server, $reason);
        $danger = DomainStatuses::dangerous($flags);
        $this->assertSecretFree($snapshot->toArray(), $secrets);
        if (! $danger && $reason === 'lookup_unavailable') {
            return $this->unavailable($domain, $context, $response->source->value, $response->server, $snapshot);
        }
        $threshold = $context->config['expires_soon_threshold_in_days'] ?? $defaults['expires_soon_threshold_in_days'];
        if (! is_int($threshold) || $threshold < 0) {
            throw new \InvalidArgumentException('Domain expiry threshold must be a nonnegative integer.');
        }
        $expiry = $expires === null ? null : CarbonImmutable::parse($expires);
        $comparisonTime = $snapshot->date_precision === 'day' ? $context->startedAt->startOfDay() : $context->startedAt;
        [$result, $summary] = match (true) {
            $danger => [RunResult::Failed, 'domain.registration_at_risk'],
            $expiry instanceof CarbonImmutable && $expiry->lte($comparisonTime) => [RunResult::Failed, 'domain.expired'],
            $expiry instanceof CarbonImmutable && $expiry->lte($comparisonTime->addDays($threshold)) => [RunResult::Warning, 'domain.expires_soon'],
            $reason === 'registry_does_not_publish' => [RunResult::Succeeded, 'domain.registry_does_not_publish'],
            default => [RunResult::Succeeded, 'domain.valid'],
        };
        $previous = $this->store->latest($context->checkId);
        $events = [];
        if ($previous !== null) {
            $this->assertSecretFree($previous['snapshot'], $secrets);
            foreach (['expiry_changed' => ['expires_at', 'source', 'date_precision'], 'registration_changed' => ['registrar', 'domain_statuses']] as $event => $keys) {
                $old = array_intersect_key($previous['snapshot'], array_flip($keys));
                $new = array_intersect_key($snapshot->toArray(), array_flip($keys));
                if ($event === 'expiry_changed') {
                    if (! is_string($old['expires_at']) || ! is_string($new['expires_at'])) {
                        continue;
                    }
                    $oldDay = CarbonImmutable::parse($old['expires_at'], 'UTC')->utc()->startOfDay();
                    $newDay = CarbonImmutable::parse($new['expires_at'], 'UTC')->utc()->startOfDay();
                    // WHOIS precision and source switches can move the date by one day without renewal.
                    if ($oldDay->diffInDays($newDay, true) <= 1) {
                        continue;
                    }
                }
                ksort($old);
                ksort($new);
                if (isset($old['domain_statuses'], $new['domain_statuses'])) {
                    /** @var array<string, bool> $oldFlags */
                    $oldFlags = $old['domain_statuses'];
                    /** @var array<string, bool> $newFlags */
                    $newFlags = $new['domain_statuses'];
                    ksort($oldFlags);
                    ksort($newFlags);
                    $old['domain_statuses'] = $oldFlags;
                    $new['domain_statuses'] = $newFlags;
                }
                if ($old !== $new) {
                    $events[] = new AddonEvent('domain.'.$event, 'domain:'.$event.':'.$context->checkId.':'.$previous['run_id'].':'.hash('sha256', json_encode($new, JSON_THROW_ON_ERROR)), 1, ['domain' => $domain, 'old' => $old, 'new' => $new], presentationParams: []);
                }
            }
        }
        /** @var array<string, mixed> $row */
        $row = $snapshot->toArray();
        $this->store->stage($context, 'snapshot', [$row]);

        return new CheckOutcome($result, $summary, [], new DomainMetrics($expires, $reason), $snapshot, $events);
    }

    private function rdapDate(DomainResponse $response, string $action): ?string
    {
        $events = $response->get('events') ?? [];
        if (! is_array($events)) {
            throw new \UnexpectedValueException('RDAP events must be an array.');
        }
        foreach ($events as $event) {
            if (! is_array($event)) {
                throw new \UnexpectedValueException('RDAP event must be an object.');
            }
            if (($event['eventAction'] ?? null) === $action) {
                $date = $event['eventDate'] ?? null;
                if (! is_string($date) || $date === '') {
                    throw new \UnexpectedValueException('RDAP event must carry a date.');
                }

                // TODO: Use the package date method after its offset fix: https://github.com/spatie/laravel-rdap/issues
                return CarbonImmutable::parse($date, 'UTC')->utc()->toIso8601String();
            }
        }

        return null;
    }

    private function whoisDate(?string $date): ?string
    {
        // Whoeasy drops timezone offsets; a fallback date never claims time-of-day precision.
        return $date === null ? null : CarbonImmutable::parse($date, 'UTC')->toDateString();
    }

    /** @param list<string> $secrets */
    private function assertSecretFree(mixed $value, #[\SensitiveParameter] array $secrets): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertSecretFree($key, $secrets);
                $this->assertSecretFree($child, $secrets);
            }

            return;
        }
        if (is_scalar($value)) {
            foreach ($secrets as $secret) {
                if (mb_stripos((string) $value, $secret, encoding: 'UTF-8') !== false) {
                    throw new \UnexpectedValueException('lookup response contained a configured secret value');
                }
            }
        }
    }

    private function unavailable(string $domain, CheckContext $context, string $source, string $server, ?DomainSnapshot $snapshot = null, ?CheckError $error = null, ?FetchFailureKind $failureKind = null, ?FetchFailure $policyFailure = null): CheckOutcome
    {
        $snapshot ??= new DomainSnapshot($domain, null, null, null, null, null, $context->startedAt->toIso8601String(), [], [], null, $source === 'rdap' ? 'exact' : 'day', $source, $server, 'lookup_unavailable');

        $summary = match ($failureKind) {
            FetchFailureKind::PrivateTarget => 'checks.engine.private_target',
            FetchFailureKind::RedirectBlocked => 'checks.engine.redirect_blocked',
            default => 'domain.lookup_unavailable',
        };

        $cause = $policyFailure?->isPolicyRefusal() === true ? $policyFailure->policySummary() : ['key' => $summary, 'params' => []];

        return new CheckOutcome(RunResult::Errored, $cause['key'], $cause['params'], null, $snapshot, error: $error ?? new CheckError(\UnexpectedValueException::class, 'Domain expiry lookup unavailable.'));
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }
}
