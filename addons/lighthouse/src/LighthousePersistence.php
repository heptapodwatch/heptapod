<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\DailyRollupReader;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class LighthousePersistence implements RunPersistence
{
    public function __construct(private DailyRollupReader $dailyRollupReader) {}

    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'report' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Lighthouse persistence requires one report row.');
        }
        /** @var array{json_report: string, metrics: array<string, mixed>, evidence: array<string, mixed>} $row */
        $row = Validator::make($rows[0], [
            'json_report' => ['required', 'string', 'json', 'max:8388608'],
            'metrics' => ['required', 'array'], 'evidence' => ['required', 'array'],
        ])->validate();
        $compressed = gzencode($row['json_report']);
        if ($compressed === false) {
            throw new \RuntimeException('Lighthouse report compression failed.');
        }
        DB::transaction(function () use ($context, $row, $compressed): void {
            $existing = DB::table('lighthouse_reports')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted Lighthouse report.');
            }
            DB::table('lighthouse_reports')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
                'site_id' => $context->siteId, 'check_id' => $context->checkId,
                // Base64 keeps compressed bytes portable through Laravel's PostgreSQL bindings.
                'compressed_report' => base64_encode($compressed),
                'metrics' => json_encode($row['metrics'], JSON_THROW_ON_ERROR),
                'evidence' => json_encode($row['evidence'], JSON_THROW_ON_ERROR),
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('lighthouse_reports')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Browser failures produce accepted errored runs without a staged report.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{run_ended_at: string|null, check_id: int, metrics: string} $row */
            if ($row->run_ended_at !== null) {
                if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                    throw new \LogicException('Lighthouse attempt was finalized for a different run timestamp.');
                }

                return;
            }
            $endedAt = $run->endedAt->format('Y-m-d H:i:s.uP');
            $query->update(['run_ended_at' => $endedAt]);
            /** @var array<string, int|float|null> $metrics */
            $metrics = json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR);
            $scores = [];
            foreach (['performance', 'accessibility', 'best_practices', 'seo'] as $category) {
                $scores[$category] = $metrics[$category.'_score'];
            }
            DB::table('lighthouse_baselines')->updateOrInsert(['check_id' => $row->check_id], [
                'run_id' => $run->id, 'run_ended_at' => $endedAt, 'scores' => json_encode($scores, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** @return array{run_id: int, scores: array<string, int>}|null */
    public function baseline(int $checkId): ?array
    {
        $row = DB::table('lighthouse_baselines')->where('check_id', $checkId)->first();
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{run_id: int, scores: string} $row */
        /** @var array<string, int> $scores */
        $scores = json_decode($row->scores, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'scores' => $scores];
    }

    public function metrics(int $checkId, RunReference $run): ?LighthouseMetrics
    {
        $row = DB::table('lighthouse_reports')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->first(['metrics']);
        // Raw-report retention can remove metrics while core still retains bounded run evidence.
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{metrics: string} $row */

        return LighthouseMetrics::from(json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR));
    }

    public function history(int $checkId): LighthouseHistoryData
    {
        $run = DB::table('check_runs')->where('check_id', $checkId)->whereRaw("metrics ->> 'performance_score' IS NOT NULL")
            ->orderByDesc('ended_at')->orderByDesc('id')->first(['id', 'ended_at', 'metrics', 'evidence']);
        /** @var (\stdClass&object{id: int, ended_at: string, metrics: string, evidence: string})|null $run */
        /** @var array<string, mixed> $evidence */
        $evidence = $run === null ? [] : json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR);
        $until = CarbonImmutable::now('UTC')->startOfDay()->addDay();
        $scores = [];
        foreach ($this->dailyRollupReader->daily($checkId, $until->subDays(90), $until) as $bucket) {
            $average = $bucket['metric_averages'];
            $scores[] = [
                'day' => $bucket['bucket_start']->toDateString(),
                'performance' => $average['performance_score'] ?? null,
                'accessibility' => $average['accessibility_score'] ?? null,
                'best_practices' => $average['best_practices_score'] ?? null,
                'seo' => $average['seo_score'] ?? null,
            ];
        }

        return new LighthouseHistoryData($run?->id, $run === null ? null : CarbonImmutable::parse($run->ended_at)->utc()->toIso8601String(),
            $run === null ? null : LighthouseMetrics::from(json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR)),
            in_array($evidence['device_emulation'] ?? null, ['mobile', 'desktop'], true) ? $evidence['device_emulation'] : null, $scores);
    }

    public function prune(CarbonImmutable $before): void
    {
        // Baseline scores live separately and survive raw-report retention.
        DB::table('lighthouse_reports')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('lighthouse_reports')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }
}
