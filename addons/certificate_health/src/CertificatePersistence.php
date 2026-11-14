<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CertificatePersistence implements RunPersistence
{
    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'snapshot' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Certificate persistence requires one snapshot row.');
        }
        $snapshot = Validator::make($rows[0], [
            'certificate_details' => ['present', 'nullable', 'array:fingerprint,issuer,domain,additional_domains,valid_from,valid_until,signature_algorithm,is_valid', 'required_array_keys:fingerprint,issuer,domain,additional_domains,valid_from,valid_until,signature_algorithm,is_valid'],
            'certificate_details.fingerprint' => ['required_with:certificate_details', 'string', 'size:64'],
            'certificate_details.issuer' => ['sometimes', 'string'],
            'certificate_details.domain' => ['sometimes', 'string'],
            'certificate_details.additional_domains' => ['sometimes', 'array', 'list'],
            'certificate_details.additional_domains.*' => ['string'],
            'certificate_details.valid_from' => ['required_with:certificate_details', 'date'],
            'certificate_details.valid_until' => ['required_with:certificate_details', 'date'],
            'certificate_details.signature_algorithm' => ['required_with:certificate_details', 'string'],
            'certificate_details.is_valid' => ['required_with:certificate_details', 'boolean:strict'],
            'certificate_checks' => ['required', 'array', 'list', 'max:4'],
            'certificate_checks.*' => ['array:type,passed,label'],
            'certificate_checks.*.type' => ['required', 'string'],
            'certificate_checks.*.passed' => ['required', 'boolean:strict'],
            'certificate_checks.*.label' => ['required', 'string'],
            'certificate_chain_issuers' => ['present', 'array', 'list', 'max:20'],
            'certificate_chain_issuers.*' => ['string'],
        ])->validate();
        DB::transaction(function () use ($context, $snapshot): void {
            $existing = DB::table('certificate_health_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted certificate snapshot.');
            }
            DB::table('certificate_health_snapshots')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
                'site_id' => $context->siteId,
                'check_id' => $context->checkId,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('certificate_health_snapshots')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Transport failures can be accepted errored runs without any staged observation.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{snapshot: string, site_id: int, run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException('Certificate attempt was finalized for a different run timestamp.');
                }

                return;
            }
            /** @var array{certificate_details: array<string, mixed>|null} $snapshot */
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $details = $snapshot['certificate_details'];
            $endedAt = $run->endedAt->format('Y-m-d H:i:s.uP');
            $query->update(['run_ended_at' => $endedAt]);
            if ($details !== null) {
                $details['additional_domains'] = json_encode($details['additional_domains'], JSON_THROW_ON_ERROR);
                DB::table('detected_certificates')->upsert([
                    [...$details, 'site_id' => $row->site_id, 'created_at' => $endedAt, 'updated_at' => $endedAt],
                ], ['site_id', 'fingerprint'], ['issuer', 'domain', 'additional_domains', 'valid_from', 'valid_until', 'signature_algorithm', 'is_valid', 'updated_at']);
            }
        });
    }

    public function detail(int $checkId): ?CertificateSnapshot
    {
        $row = DB::table('certificate_health_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['snapshot']);
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{snapshot: string} $row */

        return CertificateSnapshot::from(json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR));
    }

    public function timeline(int $checkId): CertificateTimelineData
    {
        $rows = DB::table('certificate_health_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->limit(21)->get(['run_id', 'run_ended_at', 'snapshot']);
        $observations = [];
        foreach ($rows as $row) {
            /** @var \stdClass&object{run_id: int, run_ended_at: string, snapshot: string} $row */
            /** @var array{certificate_details: array{fingerprint: string, issuer: string, valid_from: string, valid_until: string, domain: string, additional_domains: list<string>}|null} $snapshot */
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $certificate = $snapshot['certificate_details'];
            $observations[] = [
                'run_id' => $row->run_id, 'observed_at' => CarbonImmutable::parse($row->run_ended_at)->utc()->toIso8601String(),
                'fingerprint' => $certificate['fingerprint'] ?? null,
                'issuer' => $certificate['issuer'] ?? null,
                'valid_from' => isset($certificate['valid_from']) ? CarbonImmutable::parse($certificate['valid_from'])->utc()->toIso8601String() : null,
                'valid_until' => isset($certificate['valid_until']) ? CarbonImmutable::parse($certificate['valid_until'])->utc()->toIso8601String() : null,
                'names' => $certificate === null ? [] : array_values(array_unique(array_filter([$certificate['domain'], ...$certificate['additional_domains']], static fn (string $name): bool => $name !== ''))),
                'changed' => null,
            ];
        }
        foreach ($observations as $index => &$observation) {
            $previous = $observations[$index + 1] ?? null;
            if ($previous !== null) {
                $observation['changed'] = $observation['fingerprint'] !== $previous['fingerprint']
                    || $observation['issuer'] !== $previous['issuer']
                    || $observation['valid_from'] !== $previous['valid_from']
                    || $observation['valid_until'] !== $previous['valid_until'];
            }
        }
        unset($observation);

        $groups = [];
        foreach (array_slice($observations, 0, 20) as $observation) {
            $last = array_key_last($groups);
            if ($last !== null && $observation['changed'] === false && $groups[$last]['changed'] === false
                && $observation['fingerprint'] === $groups[$last]['fingerprint']
                && $observation['issuer'] === $groups[$last]['issuer']
                && $observation['valid_from'] === $groups[$last]['valid_from']
                && $observation['valid_until'] === $groups[$last]['valid_until']) {
                $groups[$last]['count']++;
                $groups[$last]['first_observed_at'] = $observation['observed_at'];
            } else {
                $groups[] = [...$observation, 'count' => 1, 'first_observed_at' => $observation['observed_at']];
            }
        }

        return new CertificateTimelineData($groups);
    }

    /** @return array{run_id: int, certificate: array{fingerprint: string, issuer: string, valid_to: string}}|null */
    public function latestCertificate(int $checkId): ?array
    {
        $row = DB::table('certificate_health_snapshots')
            ->where('check_id', $checkId)
            ->whereNotNull('run_ended_at')
            ->whereNotNull('snapshot->certificate_details->fingerprint')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')
            ->first(['run_id', 'snapshot']);
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{run_id: int, snapshot: string} $row */
        /** @var array{certificate_details: array{fingerprint: string, issuer: string, valid_until: string}} $snapshot */
        $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $details = $snapshot['certificate_details'];

        return ['run_id' => $row->run_id, 'certificate' => [
            'fingerprint' => $details['fingerprint'], 'issuer' => $details['issuer'], 'valid_to' => $details['valid_until'],
        ]];
    }

    public function prunedTables(): array
    {
        return ['certificate_health_snapshots'];
    }

    public function prune(CarbonImmutable $before): void
    {
        // Preserve the certificate-bearing baseline even after errors or a long pause.
        $baselines = DB::table('certificate_health_snapshots')
            ->selectRaw('DISTINCT ON (check_id) id')
            ->whereNotNull('run_ended_at')->whereNotNull('snapshot->certificate_details->fingerprint')
            ->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');
        DB::table('certificate_health_snapshots')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))
            ->whereNotIn('id', $baselines)->delete();
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('certificate_health_snapshots')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }
}
