<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckRunEvidence;
use Heptapod\Contracts\Findings\AvailableFindingsSnapshot;
use Heptapod\Contracts\Findings\FindingsCoverage;
use Heptapod\Contracts\Findings\FindingsFilter;
use Heptapod\Contracts\Findings\FindingsPosition;
use Heptapod\Contracts\Findings\FindingsRow;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\FindingsRequest;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Support\Api\ApiProblem;
use Heptapod\Support\Api\CursorCodec;
use Heptapod\Support\RedactionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

#[Group('Check runs')]
final class FindingsController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(FindingsRequest $request, IncidentsReader $incidents, CheckRegistry $registry, CursorCodec $cursors): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        /** @var array{incident_id?: int|string, snapshot?: string, check_id?: int|string, run_ref?: array{id: int|string, endedAt: string}, baseline_run_ref?: array{id: int|string, endedAt: string}, status?: string, only_new_since?: string, limit?: int|string, cursor?: string} $input */
        $input = $request->validated();
        $incident = isset($input['incident_id']) ? $incidents->scoped($user, includeArchived: true)->findOrFail($input['incident_id']) : null;
        $check = $incident instanceof Incident ? Check::findOrFail($incident->check_id) : Check::query()->findOrFail($input['check_id'] ?? throw new \LogicException('Validated selector requires check_id.'));
        Gate::authorize('view', $check);
        $snapshotSelector = $input['snapshot'] ?? 'opening';
        $explicitRun = isset($input['run_ref']) ? $this->reference($input['run_ref']) : null;
        $selector = $incident instanceof Incident
            ? ['incident_id' => $incident->id, 'snapshot' => $snapshotSelector]
            : ['check_id' => $check->id, 'run_ref' => $explicitRun?->toArray()];
        $baselineInput = isset($input['baseline_run_ref']) ? $this->reference($input['baseline_run_ref']) : null;
        $filter = new FindingsFilter(isset($input['status']) ? FindingStatus::from($input['status']) : null, isset($input['only_new_since']));
        $limit = (int) ($input['limit'] ?? 50);
        $binding = $cursors->binding($user, $token, [$selector, $baselineInput?->toArray(), $filter->toArray(), $input['only_new_since'] ?? null, $limit]);
        $state = isset($input['cursor']) ? $cursors->decode($input['cursor'], 'findings', $binding) : null;
        if ($state !== null) {
            $run = RunReference::from($state['run']);
            $baselineRun = isset($state['baseline_run']) ? RunReference::from($state['baseline_run']) : null;
            $after = FindingsPosition::from($state['after']);
        } else {
            $run = $incident instanceof Incident
                ? (($snapshotSelector === 'opening') ? ($this->opening($incident) ?? throw new ApiProblem('no_dataset', 409, ['reason' => 'legacy_metadata_missing'])) : $this->latest($check))
                : ($explicitRun ?? throw new \LogicException('Validated check selector requires a run.'));
            $baselineRun = $incident instanceof Incident ? $this->opening($incident) : $baselineInput;
            $after = null;
        }
        $provider = $registry->get($check->type)->persistence();
        if (! $provider instanceof FindingsQueryProvider) {
            throw new ApiProblem('unsupported', 409);
        }
        $redaction = RedactionContext::forSite(Site::findOrFail($check->site_id), $check);

        return DB::transaction(function () use ($provider, $check, $run, $baselineRun, $state, $after, $filter, $limit, $selector, $incident, $redaction, $cursors, $binding): JsonResponse {
            $source = $provider->snapshot($check->type, $check->id, $run);
            $raw = $state === null ? $this->owned($check, $run, $source, $incident) : null;
            if ($source instanceof UnavailableFindingsSnapshot) {
                $details = $source->toArray();
                if ($raw instanceof CheckRun) {
                    $observation = CheckRunEvidence::observation($raw->evidence);
                    $details['coverage'] = FindingsCoverage::forRetainedObservation($observation)?->toArray();
                    if (($observation['comparison_limit_reached'] ?? null) === true) {
                        $details['reason'] = 'comparison_limit';
                    }
                }
                throw new ApiProblem($state !== null ? 'evidence_expired' : $source->status->value, $state !== null ? 410 : 409, $details);
            }
            if ($state !== null && ($state['snapshot_ref'] !== $source->snapshot_ref || $state['dataset_version'] !== $source->dataset_version)) {
                throw new ApiProblem('evidence_expired', 410);
            }
            if ($filter->status instanceof FindingStatus && ! in_array($filter->status, $source->supported_filters, true)) {
                throw new ApiProblem('unsupported_filter', 422);
            }
            $baseline = null;
            $baselineStatus = $incident instanceof Incident ? 'no_dataset' : 'not_requested';
            if ($baselineRun instanceof RunReference) {
                $candidate = $provider->snapshot($check->type, $check->id, $baselineRun);
                if ($state === null || ($state['baseline_ref'] ?? null) === null) {
                    $this->owned($check, $baselineRun, $candidate, $incident);
                }
                if ($baselineRun->endedAt->gt($run->endedAt) || ($baselineRun->endedAt->eq($run->endedAt) && $baselineRun->id > $run->id)) {
                    throw new ApiProblem('invalid_baseline', 422);
                }
                if ($candidate instanceof AvailableFindingsSnapshot) {
                    if ($state !== null && ($state['baseline_ref'] ?? null) !== null && $state['baseline_ref'] !== $candidate->snapshot_ref) {
                        throw new ApiProblem('evidence_expired', 410);
                    }
                    $baseline = $candidate;
                    $baselineStatus = $source->snapshot_ref === $baseline->snapshot_ref || $source->comparableWith($baseline) ? 'available' : 'incomparable';
                } else {
                    if ($state !== null && ($state['baseline_ref'] ?? null) !== null) {
                        throw new ApiProblem('evidence_expired', 410);
                    }
                    $baselineStatus = $candidate->status->value;
                }
            }
            if ($filter->only_new_since && $baselineStatus !== 'available') {
                throw new ApiProblem('baseline_unavailable', 409, ['baseline_status' => $baselineStatus]);
            }
            $page = $provider->page($source, $filter, $baselineStatus === 'available' ? $baseline : null, $after, $limit);
            if ($page instanceof UnavailableFindingsSnapshot) {
                throw new ApiProblem('evidence_expired', 410, $page->toArray());
            }
            $rows = array_map(static function (FindingsRow $row) use ($incident, $redaction): array {
                /** @var array{new_since_opening: bool|null, new_since_baseline: bool|null, url: string, found_on: string|null, link_text: string|null, error_message: string|null, field_truncation: list<string>} $data */
                $data = $row->toArray();
                if ($incident instanceof Incident) {
                    $data['new_since_opening'] = $data['new_since_baseline'];
                    $data['new_since_baseline'] = null;
                }
                foreach (['url', 'found_on', 'link_text', 'error_message'] as $field) {
                    if (is_string($data[$field])) {
                        $data[$field] = SecretRedactor::redact($data[$field], $redaction->secrets, $redaction->urls);
                        if (mb_strlen($data[$field]) > 4096) {
                            $data[$field] = mb_substr($data[$field], 0, 4096);
                            $data['field_truncation'][] = $field;
                        }
                    }
                }

                return $data;
            }, $page->rows);
            $cursor = $page->next_position instanceof FindingsPosition ? $cursors->encode('findings', $binding,
                ['run' => $run->toArray(), 'baseline_run' => $baselineRun?->toArray(), 'snapshot_ref' => $source->snapshot_ref,
                    'baseline_ref' => $baseline?->snapshot_ref, 'dataset_version' => $source->dataset_version, 'after' => $page->next_position->toArray()]) : null;

            return response()->json(['selector' => $selector, 'source' => $page->snapshot->toArray(),
                'baseline' => ['status' => $baselineStatus, 'definition' => $incident instanceof Incident ? 'since_opening' : ($baselineRun instanceof RunReference ? 'since_baseline' : null), 'source' => $baseline?->toArray()],
                'rows' => $rows, 'has_more' => $page->has_more, 'next_cursor' => $cursor])->header('Cache-Control', 'private, no-store');
        });
    }

    /** @param array{id: int|string, endedAt: string} $reference */
    private function reference(array $reference): RunReference
    {
        return new RunReference((int) $reference['id'], CarbonImmutable::parse($reference['endedAt'])->utc());
    }

    private function opening(Incident $incident): ?RunReference
    {
        $evidence = $incident->evidence;
        if (! is_int($evidence['run_id'] ?? null) || ! is_string($evidence['run_ended_at'] ?? null)) {
            return null;
        }

        return new RunReference($evidence['run_id'], CarbonImmutable::parse($evidence['run_ended_at'])->utc());
    }

    private function latest(Check $check): RunReference
    {
        if ($check->latest_run_id === null || $check->latest_run_ended_at === null) {
            throw new ApiProblem('no_dataset', 409, ['reason' => 'no_observation']);
        }

        return new RunReference($check->latest_run_id, $check->latest_run_ended_at);
    }

    private function owned(Check $check, RunReference $run, AvailableFindingsSnapshot|UnavailableFindingsSnapshot $source, ?Incident $incident): ?CheckRun
    {
        if ($source instanceof AvailableFindingsSnapshot) {
            return null;
        }
        $raw = CheckRun::forRun($run)->first(['check_id', 'evidence']);
        if ($raw !== null) {
            abort_unless($raw->check_id === $check->id, 404);

            return $raw;
        }
        $known = ($check->latest_run_id === $run->id && $check->latest_run_ended_at?->eq($run->endedAt) === true)
            || ($incident instanceof Incident && ($incident->evidence['run_id'] ?? null) === $run->id && is_string($incident->evidence['run_ended_at'] ?? null) && CarbonImmutable::parse($incident->evidence['run_ended_at'])->eq($run->endedAt));
        abort_unless($known, 404);

        return null;
    }
}
