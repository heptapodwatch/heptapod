<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CertificateFetcher;
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
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\DiffBlock;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\TlsFailureReason;
use Spatie\SslCertificate\SslCertificate;

final readonly class CertificateHealthCheck implements CheckDetailProvider, CheckHistoryProvider, CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private CertificateFetcher $fetcher, private CertificatePersistence $store) {}

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?CertificateSnapshot
    {
        return $this->store->detail($checkId);
    }

    public function history(int $checkId, bool $canViewCapabilities = false): CertificateTimelineData
    {
        $timeline = $this->store->timeline($checkId);
        $observations = $timeline->observations;
        foreach ($observations as $index => &$observation) {
            $previous = $observations[$index + 1] ?? null;
            $observation['history_label'] = null;
            if ($observation['changed'] === true && $previous !== null) {
                $observation['history_label'] = $observation['names'] !== [] && $previous['names'] !== [] && $observation['names'] !== $previous['names']
                    ? new CheckRunPresentationPartData('checks.history.certificate_changed_names', ['current' => implode(', ', $observation['names']), 'previous' => implode(', ', $previous['names'])])
                    : ($observation['valid_until'] !== null && $previous['valid_until'] !== null && $observation['valid_until'] > $previous['valid_until']
                        ? new CheckRunPresentationPartData('checks.history.certificate_renewed', ['date' => $observation['valid_until']])
                        : new CheckRunPresentationPartData('checks.history.certificate_changed'));
            }
        }
        unset($observation);

        return new CertificateTimelineData($observations);
    }

    public function presentRun(CheckRunPresentationInput $run): CheckRunPresentationData
    {
        $change = $run->eventPayload('certificate_health.certificate_changed') === null ? null : new CheckRunPresentationPartData('checks.history.certificate_changed');
        if ($change instanceof CheckRunPresentationPartData && $run->result === RunResult::Succeeded) {
            return new CheckRunPresentationData([$change]);
        }
        $evidence = $run->observationEvidence();
        if ($evidence !== []) {
            CertificateSnapshot::from($evidence);
        }
        if (str_starts_with($run->summary, 'causes.tls.')) {
            // The native classifier's accepted reason must not be replaced by facts from a newer certificate.
            $keys = array_map(static fn (TlsFailureReason $reason): string => 'causes.tls.'.$reason->value, TlsFailureReason::cases());
            $keys = [...$keys, 'causes.tls.name_mismatch_subject', 'causes.tls.name_mismatch_subject_more', 'causes.tls.expired_date', 'causes.tls.not_yet_valid_date'];
            if (! in_array($run->summary, $keys, true)) {
                throw new \LogicException('Unknown classified TLS reason.');
            }
            $required = str_contains($run->summary, '_subject') ? ['subject', 'host'] : (str_ends_with($run->summary, '_date') ? ['date'] : []);
            foreach ($required as $name) {
                if (! is_string($run->summaryParams[$name] ?? null) || $run->summaryParams[$name] === '') {
                    throw new \LogicException('Classified TLS reason parameters are invalid.');
                }
                if ($name === 'date' && (preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $run->summaryParams[$name]) !== 1
                    || CarbonImmutable::parse($run->summaryParams[$name])->format('Y-m-d') !== substr($run->summaryParams[$name], 0, 10))) {
                    throw new \LogicException('Classified TLS reason date is invalid.');
                }
            }
        }

        $tls = array_find(TlsFailureReason::cases(), static fn (TlsFailureReason $reason): bool => $run->summary === 'causes.tls.'.$reason->value || str_starts_with($run->summary, 'causes.tls.'.$reason->value.'_'));

        // "Expires in 1 day": the expiry headline pluralizes on its day count.
        $params = $run->summary === 'causes.certificate_expiry' && is_int($run->summaryParams['days'] ?? null)
            ? [...$run->summaryParams, 'count' => $run->summaryParams['days']] : $run->summaryParams;
        $parts = [new CheckRunPresentationPartData($run->summary, $params, shortKey: $tls === null ? null : 'certificate_health.short.'.$tls->value)];

        return new CheckRunPresentationData($parts, changes: $change instanceof CheckRunPresentationPartData && in_array($run->result, [RunResult::Warning, RunResult::Failed], true) ? [$change] : []);
    }

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'certificate_health.mail_event.check.failing',
            'check.warning' => 'certificate_health.mail_event.check.warning',
            'check.recovered' => 'certificate_health.mail_event.check.recovered',
            'certificate.expiring' => 'certificate_health.mail_event.certificate.expiring',
            'certificate_health.certificate_changed' => 'certificate_health.mail_event.certificate_health.certificate_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'certificate_health.certificate_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Changed, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        if ($event->type === 'certificate_health.certificate_changed') {
            $lines = [];
            foreach (['old' => '-', 'new' => '+'] as $side => $sign) {
                $certificate = $event->payload[$side] ?? null;
                if (! is_array($certificate) || ! is_string($certificate['fingerprint'] ?? null) || ! is_string($certificate['issuer'] ?? null) || ! is_string($certificate['valid_to'] ?? null)) {
                    throw new \LogicException('Certificate change requires retained fingerprint, issuer and expiry.');
                }
                $date = CarbonImmutable::parse($certificate['valid_to'])->setTimezone($timezone);
                $date->locale($locale);
                $lines[] = ['sign' => $sign, 'text' => $certificate['issuer'].' · '.$date->isoFormat('ll').' · '.$certificate['fingerprint'], 'tone' => MailTone::Changed];
            }

            return [new DiffBlock(__('certificate_health.mail_changed', locale: $locale), $lines)];
        }
        $evidence = $run->observationEvidence();
        if ($evidence === []) {
            return [];
        }
        $snapshot = CertificateSnapshot::from($evidence);
        $details = $snapshot->certificate_details;
        if ($details === null) {
            return [new NoteBlock(__('certificate_health.mail_no_certificate', locale: $locale))];
        }
        $host = $run->summaryParams['host'] ?? null;
        $rows = [];
        if (is_string($host)) {
            $rows[] = ['label' => __('certificate_health.mail_host', locale: $locale), 'value' => new Cell($host, mono: true)];
        }
        $names = array_values(array_unique([$details['domain'], ...$details['additional_domains']]));
        $until = CarbonImmutable::parse($details['valid_until'])->setTimezone($timezone);
        $until->locale($locale);
        $days = (int) $observedAt->diffInDays($until);
        $rows[] = ['label' => __('certificate_health.mail_names', locale: $locale), 'value' => new Cell(implode(', ', $names), mono: true, tone: str_starts_with($run->summary, 'causes.tls.name_mismatch') ? MailTone::Failed : null)];
        $rows[] = ['label' => __('certificate_health.mail_issuer', locale: $locale), 'value' => new Cell($details['issuer'])];
        $rows[] = ['label' => __('certificate_health.mail_valid_until', locale: $locale), 'value' => new Cell($until->isoFormat('ll').' · '.trans_choice('certificate_health.mail_days', $days, ['days' => $days], $locale), tone: $days < 0 ? MailTone::Failed : null)];

        return [new FactsBlock(__('certificate_health.mail_served', locale: $locale), $rows)];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('certificate_health.mail_recovery_confirmed', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(
            CheckKey::CertificateHealth, 'checks.certificate_health.label', 'shield-check',
            'checks-fast', 30, 3600, 1, ExecutionMode::Poll,
            new Schema(['expires_soon_threshold_in_days' => new Field(FieldType::Integer, ['sometimes', 'min:0'], presentation: new FieldPresentation('certificate_health.threshold'), default: 14)]),
            new Schema([
                'issuer' => new Field(FieldType::String, ['present', 'nullable']),
                'fingerprint' => new Field(FieldType::String, ['present', 'nullable']),
                'is_valid' => new Field(FieldType::Boolean, ['required']),
            ]),
            emittedEvents: ['certificate_health.certificate_changed'],
            requiresHttps: true,
            detailDataClass: CertificateSnapshot::class,
        );
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        $defaults = $this->definition()->configSchema->defaults();
        $url = parse_url($context->url);
        if ($url === false || ! isset($url['scheme'], $url['host']) || ! in_array($url['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Certificate check requires a valid HTTP(S) URL.');
        }
        if ($url['scheme'] !== 'https') {
            return $this->outcome($context, RunResult::Failed, 'certificate_health.not_https', new CertificateSnapshot(null, [['type' => 'https', 'passed' => false, 'label' => 'certificate_health.check.https']], []));
        }
        $host = $url['host'];
        $port = $url['port'] ?? 443;
        $fetched = $this->fetcher->fetch($host, $port, $host);
        if ($fetched->hostname !== $host || $fetched->port !== $port) {
            throw new \UnexpectedValueException('TLS response endpoint differs from request.');
        }
        $chain = [];
        foreach (array_slice($fetched->pemChain, 0, 20) as $pem) {
            // Unreadable display PEM stops detail parsing; it cannot override native verification.
            if (strlen($pem) > 65536 || @openssl_x509_read($pem) === false) {
                break;
            }
            $chain[] = SslCertificate::createFromString($pem);
        }
        $leaf = $chain[0] ?? null;
        $valid = $fetched->verified;
        $checks = ['native_verification' => $valid];
        /** @var list<string> $additionalDomains */
        $additionalDomains = $leaf?->getAdditionalDomains() ?? [];
        $details = $leaf instanceof SslCertificate ? [
            'fingerprint' => $leaf->getFingerprintSha256(),
            'issuer' => $leaf->getIssuer(),
            'domain' => $leaf->getDomain(),
            'additional_domains' => $additionalDomains,
            'valid_from' => $leaf->validFromDate()->toIso8601String(),
            'valid_until' => $leaf->expirationDate()->toIso8601String(),
            'signature_algorithm' => $leaf->getSignatureAlgorithm(),
            'is_valid' => $valid,
        ] : null;
        $snapshot = new CertificateSnapshot($details, array_map(static fn (string $type, bool $passed): array => ['type' => $type, 'passed' => $passed, 'label' => 'certificate_health.check.'.$type], array_keys($checks), array_values($checks)), array_map(static fn (SslCertificate $certificate): string => $certificate->getIssuer(), $chain));
        if (! $valid) {
            $failure = $fetched->failureSummary();

            return $this->outcome($context, RunResult::Failed, $failure['key'], $snapshot, $valid, $failure['params']);
        }
        $threshold = $context->config['expires_soon_threshold_in_days'] ?? $defaults['expires_soon_threshold_in_days'];
        if (! is_int($threshold) || $threshold < 0) {
            throw new \InvalidArgumentException('Certificate expiry threshold must be a nonnegative integer.');
        }
        if ($leaf instanceof SslCertificate && $leaf->expirationDate()->lte($context->startedAt->addDays($threshold))) {
            return $this->outcome($context, RunResult::Warning, 'causes.certificate_expiry', $snapshot, $valid);
        }
        $previousIssuer = $this->store->latestCertificate($context->checkId)['certificate']['issuer'] ?? null;
        if ($previousIssuer !== null && $previousIssuer !== ($details['issuer'] ?? null)) {
            return $this->outcome($context, RunResult::Warning, 'certificate_health.issuer_changed', $snapshot, $valid);
        }

        return $this->outcome($context, RunResult::Succeeded, 'certificate_health.valid', $snapshot, $valid);
    }

    /** @param array<string, string> $failureParams */
    private function outcome(CheckContext $context, RunResult $result, string $summary, CertificateSnapshot $snapshot, bool $verified = false, array $failureParams = []): CheckOutcome
    {
        /** @var array<string, mixed> $row */
        $row = $snapshot->toArray();
        $this->store->stage($context, 'snapshot', [$row]);
        $details = $snapshot->certificate_details;

        $previous = $this->store->latestCertificate($context->checkId);
        $events = [];
        if ($details !== null && $previous !== null && $details['fingerprint'] !== $previous['certificate']['fingerprint']) {
            $events[] = new AddonEvent('certificate_health.certificate_changed',
                'certificate_health:'.$context->checkId.':'.$previous['run_id'].':'.$details['fingerprint'], 1, [
                    'old' => $previous['certificate'],
                    'new' => ['fingerprint' => $details['fingerprint'], 'issuer' => $details['issuer'], 'valid_to' => $details['valid_until']],
                ], presentationParams: []);
        }

        $params = $summary === 'causes.certificate_expiry' && is_string($details['valid_until'] ?? null)
            ? ['days' => (int) $context->startedAt->diffInDays(CarbonImmutable::parse($details['valid_until'])), 'presentation' => 'direct'] : $failureParams;

        return new CheckOutcome($result, $summary, $params, new CertificateMetrics($details['issuer'] ?? null, $details['fingerprint'] ?? null, $verified), $snapshot, $events);
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }
}
