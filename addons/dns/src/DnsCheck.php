<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\DnsBaselineAcceptance;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\DiffBlock;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Validator;
use Pdp\Domain;
use Spatie\Dns\Support\Types;

final readonly class DnsCheck implements CheckDetailProvider, CheckHistoryProvider, CheckRunPresenter, CheckType, DnsBaselineAcceptance, MailEvidenceProvider
{
    public const array Types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'];

    public function __construct(private DnsResolver $resolver, private DnsPersistence $store, private PublicSuffixList $suffixes) {}

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?DnsSnapshot
    {
        return ! $latestRun instanceof LatestRunEvidence || $latestRun->observationEvidence() === []
            ? null : DnsSnapshot::from($latestRun->observationEvidence())->bounded();
    }

    public function history(int $checkId, bool $canViewCapabilities = false): DnsComparisonData
    {
        return $this->store->comparison($checkId);
    }

    public function accept(int $checkId, int $actorId, string $actorName, int $expectedRunId): int
    {
        return $this->store->accept($checkId, $actorId, $actorName, $expectedRunId);
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        if ($run->result === RunResult::Errored) {
            return null;
        }
        $evidence = $run->observationEvidence();
        $missingTypes = [];
        if ($evidence !== []) {
            foreach (DnsSnapshot::from($evidence)->issues as $issue) {
                if ($issue['type'] === 'dns.issue.records_missing') {
                    $missingTypes[] = $issue['record_type'];
                }
            }
        }
        $payload = $run->eventPayload('dns.records_changed');
        $change = null;
        if ($payload !== null) {
            if (! array_key_exists('changes', $payload)) {
                if (! is_string($payload['types'] ?? null) || ! is_int($payload['added'] ?? null) || ! is_int($payload['removed'] ?? null)) {
                    throw new \LogicException('Retained DNS headline facts are invalid.');
                }
                $types = explode(', ', $payload['types']);
                $added = $payload['added'];
                $removed = $payload['removed'];
            } else {
                if (! is_array($payload['changes'])) {
                    throw new \LogicException('DNS event changes must be a map.');
                }
                $added = 0;
                $removed = 0;
                $changedTypes = [];
                foreach ($payload['changes'] as $type => $difference) {
                    if (! is_array($difference) || ! is_array($difference['added'] ?? null) || ! is_array($difference['removed'] ?? null)) {
                        throw new \LogicException('DNS event changes must contain added and removed records.');
                    }
                    if (in_array($type, $missingTypes, true)) {
                        continue;
                    }
                    $changedTypes[] = $type;
                    $added += count($difference['added']);
                    $removed += count($difference['removed']);
                }
                $types = $changedTypes;
            }
            if ($added > 0 || $removed > 0) {
                if (array_any($types, static fn (string $type): bool => ! in_array($type, self::Types, true))) {
                    throw new \LogicException('Retained changed DNS record types must be known DNS types.');
                }
                sort($types);
                $key = match (true) {
                    $added === 0 => 'dns.presentation.removed',
                    $removed === 0 => 'dns.presentation.added',
                    default => 'dns.presentation.changed',
                };
                $change = new CheckRunPresentationPartData($key, ['added' => $added, 'removed' => $removed], shortKey: 'dns.presentation.changed_short', lists: ['types' => $types]);
            }
        }
        if (in_array($run->result, [RunResult::Failed, RunResult::Warning], true) && $evidence !== []) {
            $parts = $this->issueParts($evidence);
            if ($parts !== []) {
                return new CheckRunPresentationData($change instanceof CheckRunPresentationPartData ? [...$parts, $change] : $parts);
            }
            if ($change instanceof CheckRunPresentationPartData) {
                return new CheckRunPresentationData([$change]);
            }
        }
        // Runs without retained observation evidence keep their stored summary.
        if (! $change instanceof CheckRunPresentationPartData) {
            return null;
        }

        return new CheckRunPresentationData($run->result !== RunResult::Succeeded ? [new CheckRunPresentationPartData($run->summary, $run->summaryParams)] : [$change],
            changes: $run->result !== RunResult::Succeeded ? [$change] : []);
    }

    /** @param array<string, mixed> $evidence
     * @return list<CheckRunPresentationPartData>
     */
    private function issueParts(array $evidence): array
    {
        $issues = [];
        foreach (DnsSnapshot::from($evidence)->issues as $issue) {
            $issues[$issue['type']][] = $issue;
        }
        ksort($issues);
        $parts = [];
        foreach ($issues as $type => $group) {
            $lists = [];
            if ($type === 'dns.issue.records_missing') {
                $types = array_values(array_unique(array_column($group, 'record_type')));
                if (array_any($types, static fn (?string $type): bool => ! in_array($type, self::Types, true))) {
                    throw new \LogicException('Retained missing DNS record types must be known DNS types.');
                }
                /** @var list<string> $types */
                sort($types);
                $lists = ['types' => $types];
            }
            $key = match ($type) {
                'dns.issue.records_missing', 'dns.issue.nameservers_out_of_sync', 'dns.issue.nameserver_unreachable',
                'dns.issue.nameservers_unreachable', 'dns.issue.nameservers_missing', 'dns.issue.resolves_to_nothing' => $type,
                default => throw new \LogicException('Unknown retained DNS issue type.'),
            };
            $parts[] = new CheckRunPresentationPartData($key, lists: $lists);
        }

        return $parts;
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'dns.mail_event.check.failing',
            'check.warning' => 'dns.mail_event.check.warning',
            'check.recovered' => 'dns.mail_event.check.recovered',
            'dns.records_changed' => 'dns.mail_event.dns.records_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'dns.records_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Changed, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $blocks = [];
        $changes = $event->payload['changes'] ?? null;
        if (is_array($changes) && $changes !== []) {
            $lines = [];
            $total = 0;
            foreach ($changes as $change) {
                if (! is_array($change) || ! is_array($change['added'] ?? null) || ! is_array($change['removed'] ?? null)) {
                    throw new \LogicException('Invalid retained DNS change.');
                }
                foreach (['removed' => '-', 'added' => '+'] as $side => $sign) {
                    $records = $side === 'removed' ? $change['removed'] : $change['added'];
                    $total += count($records);
                    foreach (array_slice($records, 0, max(0, 8 - count($lines))) as $record) {
                        if (! is_array($record)) {
                            throw new \LogicException('DNS change record must be structured.');
                        }
                        $lines[] = ['sign' => $sign, 'text' => $this->mailRecord($record), 'tone' => MailTone::Changed];
                    }
                }
            }
            $blocks = [new DiffBlock(__('dns.mail_changes', locale: $locale), $lines)];
            if ($total > count($lines)) {
                $blocks[] = new NoteBlock(__('dns.mail_more', ['count' => $total - count($lines)], $locale));
            }

        }
        $evidence = $run->observationEvidence();
        if ($evidence === []) {
            return $blocks;
        }
        $snapshot = DnsSnapshot::from($evidence);
        $rows = [];
        foreach (array_slice($snapshot->issues, 0, 5) as $issue) {
            $rows[] = [new Cell($issue['host'], mono: true), new Cell(__($issue['type'], ['types' => $issue['record_type'] ?? ''], $locale), tone: MailTone::Failed)];
        }
        if ($rows !== []) {
            $more = count($snapshot->issues) > count($rows) ? __('dns.mail_more', ['count' => count($snapshot->issues) - count($rows)], $locale) : null;
            if ($more !== null && ! is_string($more)) {
                throw new \LogicException('DNS more label must be text.');
            }

            return [...$blocks, new TableBlock(__('dns.mail_issues', locale: $locale), [__('dns.mail_host', locale: $locale), __('dns.mail_result', locale: $locale)], $rows, $more)];
        }

        if ($blocks !== []) {
            return $blocks;
        }

        return [new FactsBlock(__('dns.mail_records', locale: $locale), [['label' => __('dns.mail_record_count', locale: $locale), 'value' => new Cell((string) count($snapshot->dns_records))]])];
    }

    /** @param array<array-key, mixed> $record */
    private function mailRecord(array $record): string
    {
        $values = [];
        foreach ($record as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                throw new \LogicException('DNS event record values must be text or integers.');
            }
        }
        // JSONB object order is not DNS wire order; print the retained fields in their protocol order.
        foreach (['host', 'type', 'ip', 'ipv6', 'pri', 'target', 'txt', 'flags', 'tag', 'value'] as $key) {
            if (array_key_exists($key, $record)) {
                $values[] = (string) $record[$key];
            }
        }

        return mb_strcut(implode(' ', $values), 0, 512, 'UTF-8');
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('dns.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        /** @var array<int, string> $knownTypes */
        $knownTypes = Types::getTypes();
        $ignoredTypes = array_values($knownTypes);

        return new CheckDefinition(
            CheckKey::Dns, 'checks.dns.label', 'network', 'checks-fast', 120, 3600, 1, ExecutionMode::Poll,
            new Schema([
                'extra_cnames' => new Field(FieldType::List, ['sometimes', 'max:20'], items: new Field(FieldType::String, ['required', 'max:253', 'regex:/^(?=.{1,253}\\.?$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\\.)+[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\\.?$/D']), presentation: new FieldPresentation('dns.extra_cnames'), default: []),
                'monitor_main_domain' => new Field(FieldType::Boolean, ['sometimes'], presentation: new FieldPresentation('dns.monitor_main_domain'), default: false),
                'ignored_record_types' => new Field(FieldType::List, ['sometimes', 'max:'.count($ignoredTypes)], items: new Field(FieldType::String, ['required', 'distinct', 'in:'.implode(',', $ignoredTypes)], uppercase: true), presentation: new FieldPresentation('dns.ignored_record_types'), default: []),
                'check_nameservers_in_sync' => new Field(FieldType::Boolean, ['sometimes'], presentation: new FieldPresentation('dns.check_nameservers_in_sync'), default: false),
                'accept_changes_automatically' => new Field(FieldType::Boolean, ['sometimes'], presentation: new FieldPresentation('dns.accept_changes_automatically'), default: false),
            ]),
            new Schema([
                'record_count' => new Field(FieldType::Integer, ['required', 'min:0']),
                'issue_count' => new Field(FieldType::Integer, ['required', 'min:0']),
                'changed' => new Field(FieldType::Boolean, ['required']),
            ]), emittedEvents: ['dns.records_changed'], detailDataClass: DnsSnapshot::class,
        );
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        $defaults = $this->definition()->configSchema->defaults();
        $host = parse_url($context->url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \InvalidArgumentException('DNS check requires a URL hostname.');
        }
        $host = Domain::fromIDNA2008($host)->toAscii()->withoutRootLabel()->toString();
        $targets = [$host];
        if (($context->config['monitor_main_domain'] ?? $defaults['monitor_main_domain']) === true) {
            $main = $this->suffixes->rules()->resolve(Domain::fromIDNA2008($host))->registrableDomain()->toString();
            if ($main !== '') {
                $targets[] = $main;
            }
        }
        /** @var list<string> $extra */
        $extra = $context->config['extra_cnames'] ?? $defaults['extra_cnames'];
        foreach ($extra as $name) {
            $targets[] = Domain::fromIDNA2008($name)->toAscii()->withoutRootLabel()->toString();
        }
        $targets = array_values(array_unique($targets));
        sort($targets);
        /** @var list<string> $ignored */
        $ignored = $context->config['ignored_record_types'] ?? $defaults['ignored_record_types'];
        $types = array_values(array_diff(self::Types, $ignored));
        $state = $this->store->baselineState($context->checkId);
        $baseline = $state['baseline'];
        $groups = [];
        $records = [];
        $raw = [];
        $issues = [];
        $diagnostics = [];
        $authoritative = [];
        $expected = [];
        $publishBaseline = true;
        foreach ($targets as $target) {
            [$zone, $servers, $resolverFailure] = $this->authorities($context, $target);
            $authoritative = [...$authoritative, ...$servers];
            /** @var array<string, array<string, DnsLookupResult>> $serverAnswers */
            $serverAnswers = [];
            $first = null;
            $outOfSync = false;
            $sync = ($context->config['check_nameservers_in_sync'] ?? $defaults['check_nameservers_in_sync']) === true;
            $serverFailures = [];
            foreach ($servers as $server) {
                /** @var array<string, DnsLookupResult> $source */
                $source = [];
                try {
                    foreach (array_unique([...$types, 'A', 'AAAA', 'CNAME']) as $type) {
                        $source[$type] = $this->lookup($context, $target, $type, $server);
                    }
                    $serial = $sync ? $this->serial($context, $zone, $server) : null;
                } catch (ExternalNetworkException $exception) {
                    // A transport failure invalidates this NS's whole observation, not its records.
                    $this->ensureBudget($context);
                    if (! $this->recoverable($exception)) {
                        throw $exception;
                    }
                    $serverFailures[$server] = $exception;

                    continue;
                }
                $serverAnswers[$server] = $source;
                if ($sync) {
                    $set = ['serial' => $serial];
                    // Geo/anycast addresses can differ while the zone's stable records agree.
                    foreach (array_diff($types, ['A', 'AAAA']) as $type) {
                        $set[$type] = DnsSnapshot::normalize($serverAnswers[$server][$type]->records);
                    }
                    if ($first !== null && $set !== $first) {
                        $outOfSync = true;
                    }
                    $first ??= $set;
                }
            }
            if ($servers === []) {
                $issues[] = ['type' => 'dns.issue.nameservers_unreachable', 'host' => $target, 'record_type' => null];
            }
            if ($outOfSync) {
                $issues[] = ['type' => 'dns.issue.nameservers_out_of_sync', 'host' => $target, 'record_type' => null];
            }
            foreach ($serverFailures as $server => $exception) {
                $issues[] = ['type' => $serverAnswers === [] ? 'dns.issue.nameservers_unreachable' : 'dns.issue.nameserver_unreachable',
                    'host' => $target, 'record_type' => null, 'nameserver' => $server,
                    'reason' => $exception instanceof ExternalTimeoutException ? 'timeout' : 'servfail'];
            }
            $complete = $serverAnswers !== [];
            $publishBaseline = $publishBaseline && $complete && $serverFailures === [];
            /** @var array<string, DnsLookupResult> $observed */
            $observed = [];
            try {
                foreach (array_unique([...$types, 'A', 'AAAA', 'CNAME', 'NS']) as $type) {
                    $observed[$type] = $this->lookup($context, $target, $type);
                }
            } catch (ExternalNetworkException $exception) {
                $this->ensureBudget($context);
                if (! $this->recoverable($exception) || $serverAnswers === []) {
                    throw $exception;
                }
                // Use one complete authoritative observation rather than mixing partial replies.
                $observed = reset($serverAnswers);
                $resolverFailure = $exception instanceof ExternalTimeoutException ? 'timeout' : 'servfail';
            }
            if ($resolverFailure !== null && $serverAnswers !== []) {
                $diagnostics[] = ['type' => 'dns.resolver_unavailable', 'host' => $target, 'reason' => $resolverFailure];
            }
            $answers = [];
            foreach (array_unique([...$types, 'A', 'AAAA', 'CNAME']) as $type) {
                $answer = $observed[$type];
                if (in_array($type, ['A', 'AAAA'], true) && $serverAnswers !== []) {
                    $addressRecords = $addressRaw = [];
                    foreach ($serverAnswers as $source) {
                        $addressRecords = [...$addressRecords, ...$source[$type]->records];
                        $addressRaw = [...$addressRaw, ...$source[$type]->rawLines];
                    }
                    $answer = new DnsLookupResult($addressRecords, $addressRaw, null);
                }
                $answers[$type] = DnsSnapshot::normalize($answer->records);
                if (in_array($type, $types, true)) {
                    $key = $target.'|'.$type;
                    $groups[$key] = $answers[$type];
                    /** @var list<array<string, int|string>> $validatedRecords */
                    $validatedRecords = $answer->records;
                    $records = [...$records, ...$validatedRecords];
                    $raw[$type] = [...($raw[$type] ?? []), ...$answer->rawLines];
                    if ($complete && ($answers[$type] !== [] || in_array($key, $baseline['expected'] ?? [], true))) {
                        $expected[] = $key;
                        if ($answers[$type] === []) {
                            $issues[] = ['type' => 'dns.issue.records_missing', 'host' => $target, 'record_type' => $type];
                        }
                    }
                }
            }
            if (! isset($answers['A'], $answers['AAAA'], $answers['CNAME'])) {
                throw new \LogicException('DNS liveness queries are missing.');
            }
            if ($answers['A'] === [] && $answers['AAAA'] === [] && $answers['CNAME'] === []) {
                if ($servers === [] && $resolverFailure !== null) {
                    // Failed discovery cannot prove an outage from empty recursive replies.
                    throw new ExternalNetworkException('DNS authoritative nameservers could not be determined.', previous: new \RuntimeException('DNS discovery failed with '.$resolverFailure.'.'));
                }
                $issues[] = ['type' => 'dns.issue.resolves_to_nothing', 'host' => $target, 'record_type' => null];
            }
        }
        $authoritative = array_values(array_unique($authoritative));
        sort($authoritative);
        ksort($groups);
        sort($expected);
        $snapshot = new DnsSnapshot($records, $raw, $groups, $issues, $authoritative, $diagnostics);
        $changes = ! $publishBaseline || $baseline === null ? [] : $snapshot->changes($baseline['snapshot']['normalized_records']);
        $changed = $changes !== [];
        /** @var array<string, mixed> $row */
        $row = $snapshot->toArray();
        // Resolver diagnostics belong to run evidence, not the comparison baseline/history.
        unset($row['diagnostics']);
        if ($publishBaseline) {
            $this->store->stage($context, 'snapshot', [[...$row, 'expected' => $expected, 'baseline_generation' => $state['generation'], 'auto_accept_changes' => ($context->config['accept_changes_automatically'] ?? $defaults['accept_changes_automatically']) === true]]);
        }
        $missingTypes = array_column(array_filter($issues, static fn (array $issue): bool => $issue['type'] === 'dns.issue.records_missing'), 'record_type');
        $headlineChanges = array_diff_key($changes, array_flip($missingTypes));
        // dns:<check>:<run> also preserves run identity for Activity after the check is deleted.
        $events = $changed && $state['warning_episode_run_id'] === null
            ? [new AddonEvent('dns.records_changed', 'dns:'.$context->checkId.':'.$context->runId, 1, ['changes' => $changes], presentationParams: [
                'types' => implode(', ', array_keys($headlineChanges)),
                'added' => array_sum(array_map(static fn (array $change): int => count($change['added']), $headlineChanges)),
                'removed' => array_sum(array_map(static fn (array $change): int => count($change['removed']), $headlineChanges)),
            ])]
            : [];
        $failed = array_any($issues, static fn (array $issue): bool => ! in_array($issue['type'], ['dns.issue.nameserver_unreachable', 'dns.issue.nameservers_unreachable'], true));
        $result = $failed ? RunResult::Failed : ($changed || $issues !== [] ? RunResult::Warning : RunResult::Succeeded);

        return new CheckOutcome($result, $failed ? 'dns.failed' : ($issues !== [] ? 'dns.nameservers_unreachable' : ($changed ? 'dns.changed' : 'dns.succeeded')), [], new DnsMetrics(count($records), count($issues), $changed), $snapshot->bounded(), $events);
    }

    /** @return array{string, list<string>, string|null} */
    private function authorities(CheckContext $context, string $target): array
    {
        // Cross-zone CNAME replies can contain NS records belonging to the target zone.
        $zone = $target;
        $failure = null;
        do {
            try {
                $answer = $this->lookup($context, $zone, 'NS');
            } catch (ExternalNetworkException $exception) {
                $this->ensureBudget($context);
                if (! $this->recoverable($exception) || ! str_contains($zone, '.')) {
                    throw $exception;
                }
                // A recursive query for a proxied hostname may fail while its enclosing zone works.
                $failure = $exception instanceof ExternalTimeoutException ? 'timeout' : 'servfail';
                $zone = explode('.', $zone, 2)[1];

                continue;
            }
            $ns = array_values(array_filter(DnsSnapshot::normalize($answer->records), static fn (array $record): bool => $record['host'] === strtolower($zone)));
            if ($ns !== [] || ! str_contains($zone, '.')) {
                break;
            }
            $zone = explode('.', $zone, 2)[1];
        } while (true);
        $servers = [];
        foreach ($ns as $record) {
            if (isset($record['target']) && is_string($record['target'])) {
                $servers[] = $record['target'];
            }
        }
        $servers = array_values(array_unique($servers));
        sort($servers);

        return [$zone, $servers, $failure];
    }

    private function serial(CheckContext $context, string $zone, string $server): int
    {
        $records = $this->lookup($context, $zone, 'SOA', $server)->records;
        /** @var array{soa: list<array{serial: int}>} $validated */
        $validated = Validator::make(['soa' => $records], [
            'soa' => ['required', 'array', 'list', 'size:1'],
            'soa.0.host' => ['required', 'string', 'in:'.$zone],
            'soa.0.class' => ['required', 'in:IN'],
            'soa.0.type' => ['required', 'in:SOA'],
            'soa.0.serial' => ['required', 'integer:strict', 'min:0', 'max:4294967295'],
        ])->validate();

        return $validated['soa'][0]['serial'];
    }

    private function lookup(CheckContext $context, string $host, string $type, ?string $server = null): DnsLookupResult
    {
        $this->ensureBudget($context);
        $result = $this->resolver->resolve($host, $type, $server);
        if ($result->nameserver !== $server) {
            throw new \UnexpectedValueException('DNS response nameserver differs from request.');
        }
        // Validate before a later transport failure discards this answer or address union replaces it.
        if ($type !== 'SOA') {
            DnsSnapshot::normalize($result->records);
        }

        return $result;
    }

    private function recoverable(ExternalNetworkException $exception): bool
    {
        return $exception instanceof ExternalTimeoutException || ($exception instanceof DnsResolverStatusException && $exception->status === 'SERVFAIL');
    }

    private function ensureBudget(CheckContext $context): void
    {
        if (now()->toImmutable()->addSeconds(5)->greaterThan($context->deadline)) {
            throw new ExternalTimeoutException('DNS check deadline exhausted.');
        }
    }
}
