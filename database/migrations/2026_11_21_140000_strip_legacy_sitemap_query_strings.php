<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $targets = [];
        foreach (DB::table('checks')
            ->join('sites', 'sites.id', '=', 'checks.site_id')
            ->where('checks.type', 'sitemap')
            ->whereRaw("checks.config->>'path' LIKE '%?%'")
            ->orderBy('checks.id')
            ->select(['checks.id', 'checks.config', 'checks.summary_params', 'sites.url as site_url'])
            ->lazyById(200, 'checks.id', 'id') as $row) {
            /** @var stdClass&object{id: int, config: string, summary_params: string|null, site_url: string} $row */
            $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config) || ! is_string($config['path'] ?? null) || ! str_contains($config['path'], '?')) {
                continue;
            }
            $legacyPath = $config['path'];
            $cleanPath = (string) strstr($legacyPath, '?', true);
            $resolved = UriResolver::resolve(new Uri($row->site_url), new Uri($legacyPath))->withUserInfo('')->withFragment('');
            $legacyUrl = (string) $resolved;
            $cleanUrl = (string) $resolved->withQuery('');
            $targets[] = [
                'check_id' => $row->id,
                'legacy_url' => $legacyUrl,
                'clean_url' => $cleanUrl,
                'legacy_path' => $legacyPath,
                'clean_path' => $cleanPath,
                'summary_params' => $row->summary_params,
            ];
        }

        foreach ($targets as $target) {
            $checkId = $target['check_id'];
            $legacyUrl = $target['legacy_url'];
            $cleanUrl = $target['clean_url'];
            $legacyPath = $target['legacy_path'];
            $cleanPath = $target['clean_path'];

            if ($target['summary_params'] !== null) {
                $changed = false;
                $params = $this->replaceSummaryParams(json_decode($target['summary_params'], true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $legacyPath, $cleanPath, $changed);
                if ($changed) {
                    DB::table('checks')->where('id', $checkId)->update(['summary_params' => json_encode($params, JSON_THROW_ON_ERROR)]);
                }
            }

            if (Schema::hasTable('sitemap_snapshots')) {
                foreach (DB::table('sitemap_snapshots')->where('check_id', $checkId)->orderBy('id')->select(['id', 'snapshot', 'comparison_issues'])->lazyById(200, 'id') as $snapshot) {
                    /** @var stdClass&object{id: int, snapshot: string, comparison_issues: string|null} $snapshot */
                    $snapshotChanged = false;
                    $cleanSnapshot = $this->replaceExactUrl(json_decode($snapshot->snapshot, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $snapshotChanged);
                    $comparisonChanged = false;
                    $cleanComparison = $snapshot->comparison_issues === null
                        ? null
                        : $this->replaceExactUrl(json_decode($snapshot->comparison_issues, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $comparisonChanged);
                    if ($snapshotChanged || $comparisonChanged) {
                        DB::table('sitemap_snapshots')->where('id', $snapshot->id)->update([
                            'snapshot' => json_encode($cleanSnapshot, JSON_THROW_ON_ERROR),
                            'comparison_issues' => $cleanComparison === null ? null : json_encode($cleanComparison, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            }

            if (Schema::hasTable('sitemap_findings') && Schema::hasTable('sitemap_snapshots')) {
                DB::statement(<<<'SQL'
                    UPDATE sitemap_findings
                    SET url = ?,
                        identity = jsonb_set(identity, '{url}', to_jsonb(?::text)),
                        identity_hash = encode(sha256(convert_to(jsonb_set(identity, '{url}', to_jsonb(?::text))::text, 'UTF8')), 'hex')
                    WHERE snapshot_id IN (SELECT id FROM sitemap_snapshots WHERE check_id = ?)
                      AND lower(url) = lower(?)
                    SQL, [$cleanUrl, $cleanUrl, $cleanUrl, $checkId, $legacyUrl]);
            }

            foreach (DB::table('check_runs')->where('check_id', $checkId)->orderBy('id')->select(['id', 'ended_at', 'evidence', 'summary_params'])->lazyById(200, 'id') as $run) {
                /** @var stdClass&object{id: int, ended_at: string, evidence: string, summary_params: string|null} $run */
                $evidenceChanged = false;
                $evidence = $this->replaceExactUrl(json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $evidenceChanged);
                $paramsChanged = false;
                $params = $run->summary_params === null
                    ? null
                    : $this->replaceSummaryParams(json_decode($run->summary_params, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $legacyPath, $cleanPath, $paramsChanged);
                if ($evidenceChanged || $paramsChanged) {
                    DB::table('check_runs')
                        ->where('check_id', $checkId)
                        ->where('id', $run->id)
                        ->where('ended_at', $run->ended_at)
                        ->update([
                            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
                            'summary_params' => $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR),
                        ]);
                }
            }

            foreach (DB::table('incidents')->where('check_id', $checkId)->orderBy('id')->select(['id', 'evidence'])->lazyById(200, 'id') as $incident) {
                /** @var stdClass&object{id: int, evidence: string} $incident */
                $changed = false;
                $evidence = $this->replaceExactUrl(json_decode($incident->evidence, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $changed);
                if ($changed) {
                    DB::table('incidents')->where('id', $incident->id)->update(['evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
                }
            }

            foreach (DB::table('events')->where('check_id', $checkId)->orderBy('id')->select(['id', 'payload'])->lazyById(200, 'id') as $event) {
                /** @var stdClass&object{id: int, payload: string} $event */
                $changed = false;
                $payload = $this->replaceExactUrl(json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR), $legacyUrl, $cleanUrl, $changed);
                if (is_array($payload) && array_key_exists('summary_params', $payload)) {
                    $payload['summary_params'] = $this->replaceSummaryParams($payload['summary_params'], $legacyUrl, $cleanUrl, $legacyPath, $cleanPath, $changed);
                }
                if ($changed) {
                    DB::table('events')->where('id', $event->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                }
            }
        }
    }

    public function down(): void
    {
        // Stripped legacy query credentials are intentionally not restored to retained history.
    }

    /** Recursively replaces only exact complete-URL string matches, never substrings. */
    private function replaceExactUrl(mixed $value, string $legacyUrl, string $cleanUrl, bool &$changed): mixed
    {
        if (is_string($value)) {
            if (strcasecmp($value, $legacyUrl) === 0) {
                $changed = true;

                return $cleanUrl;
            }

            return $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->replaceExactUrl($child, $legacyUrl, $cleanUrl, $changed);
        }

        return $value;
    }

    private function replaceSummaryParams(mixed $params, string $legacyUrl, string $cleanUrl, string $legacyPath, string $cleanPath, bool &$changed): mixed
    {
        if (! is_array($params)) {
            return $params;
        }
        if (is_string($params['path'] ?? null)) {
            if (strcasecmp($params['path'], $legacyPath) === 0) {
                $params['path'] = $cleanPath;
                $changed = true;
            } elseif (strcasecmp($params['path'], $legacyUrl) === 0) {
                $params['path'] = $cleanUrl;
                $changed = true;
            }
        }

        return $this->replaceExactUrl($params, $legacyUrl, $cleanUrl, $changed);
    }
};
