<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Findings\AvailableFindingsSnapshot;
use Heptapod\Contracts\Findings\FindingsFilter;
use Heptapod\Contracts\Findings\FindingsPage;
use Heptapod\Contracts\Findings\FindingsPosition;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final readonly class CrawlPersistence implements FindingsExportProvider, FindingsQueryProvider, RunPersistence
{
    public const int COMPARISON_LIMIT = 25000;

    public function __construct(private CrawlFindingsQuery $findingsQuery) {}

    public function snapshot(CheckKey $type, int $checkId, RunReference $run): AvailableFindingsSnapshot|UnavailableFindingsSnapshot
    {
        return $this->findingsQuery->snapshot($type, $checkId, $run);
    }

    public function page(AvailableFindingsSnapshot $snapshot, FindingsFilter $filter, ?AvailableFindingsSnapshot $baseline, ?FindingsPosition $after, int $limit): FindingsPage|UnavailableFindingsSnapshot
    {
        return $this->findingsQuery->page($snapshot, $filter, $baseline, $after, $limit);
    }

    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'artifact' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Crawl persistence requires one artifact.');
        }
        $rules = [
            'evaluated_finding_ids' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'evaluated_finding_ids.*' => ['required', 'string', 'size:64'],
            'reclassified_finding_ids' => ['sometimes', 'nullable', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'reclassified_finding_ids.*' => ['required', 'string', 'size:64'],
            'site_url' => ['required', 'string', 'max:4096'],
            'target_fingerprint' => ['required', 'string', 'size:64'],
            'config_fingerprint' => ['required', 'string', 'size:64'],
            'comparison_key' => ['sometimes', 'string', 'size:64'],
            'classification_policy_version' => ['required', 'integer', 'in:'.LinkClassificationPolicy::VERSION],
            'collected_at' => ['required', 'date'],
            'truncated' => ['required', 'boolean:strict'],
            'page_cap_reached' => ['sometimes', 'boolean:strict'],
            'time_limit_reached' => ['sometimes', 'boolean:strict'],
            'resource_cap_reached' => ['sometimes', 'boolean:strict'],
            'observed_urls' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'observed_urls.*' => ['required', 'string'],
            'observed_pages' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'observed_pages.*' => ['required', 'string'],
            'discovered_urls' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'discovered_urls.*' => ['required', 'string'],
            'discovery_complete' => ['required', 'boolean:strict'],
            'notes' => ['present', 'array', 'list', 'max:32'],
            // Every CheckRunPresentationPartData property; a missing one makes the array rule reject every note.
            'notes.*' => ['array:key,params,labels,shortKey,untrusted,trusted,lists'],
            'notes.*.key' => ['required', 'string'],
            'notes.*.params' => ['present', 'array'],
            'notes.*.labels' => ['present', 'array'],
            'notes.*.lists' => ['sometimes', 'array', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && array_filter(array_keys($value), is_int(...)) !== []) {
                    $fail(__('validation.array', ['attribute' => $attribute]));
                }
            }],
            'notes.*.lists.*' => ['array', 'list'],
            'notes.*.lists.*.*' => ['string'],
            'settings' => ['required', 'array'],
            'urls' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'urls.*' => ['array:crawled_url,relative_crawled_url,status_code,found_on_url,relative_found_on_url,link_text,type,error_message,internal,carried_forward,current_error_message'],
            'urls.*.current_error_message' => ['present', 'nullable', 'in:'.implode(',', array_keys(CrawlFindingValues::Results))],
            'urls.*.crawled_url' => ['required', 'string'],
            'urls.*.relative_crawled_url' => ['present', 'string'],
            'urls.*.status_code' => ['present', 'nullable', 'integer', 'between:100,999'],
            'urls.*.found_on_url' => ['present', 'nullable', 'string'],
            'urls.*.relative_found_on_url' => ['present', 'nullable', 'string'],
            'urls.*.link_text' => ['present', 'nullable', 'string'],
            'urls.*.type' => ['required', 'string', 'in:'.implode(',', array_keys(CrawlFindingValues::Kinds))],
            'urls.*.error_message' => ['present', 'nullable', 'in:'.implode(',', array_keys(CrawlFindingValues::Results))],
            'urls.*.internal' => ['required', 'boolean:strict'],
            'urls.*.carried_forward' => ['sometimes', 'nullable', 'boolean:strict'],
            'mixed_content' => ['present', 'array', 'list', 'max:'.self::COMPARISON_LIMIT],
            'mixed_content.*' => ['array:element_name,mixed_content_url,found_on_url,source_url,carried_forward'],
            'mixed_content.*.element_name' => ['required', 'string', 'in:'.implode(',', array_keys(CrawlFindingValues::MixedKinds))],
            'mixed_content.*.mixed_content_url' => ['required', 'string'],
            'mixed_content.*.found_on_url' => ['required', 'string'],
            'mixed_content.*.source_url' => ['required', 'string'],
            'mixed_content.*.carried_forward' => ['sometimes', 'nullable', 'boolean:strict'],
        ];
        /** @var array{site_url: string, target_fingerprint: string, config_fingerprint: string, comparison_key?: string, classification_policy_version: int, collected_at: string, truncated: bool, notes: list<array{key: string, params: array<string, mixed>, labels: array<string, string>}>, page_cap_reached?: bool, time_limit_reached?: bool, resource_cap_reached?: bool, observed_urls: list<string>, observed_pages: list<string>, discovered_urls: list<string>, discovery_complete: bool, settings: array<string, mixed>, urls: list<array<string, mixed>>, mixed_content: list<array<string, mixed>>, evaluated_finding_ids: list<string>, reclassified_finding_ids?: list<string>|null} $values */
        $values = Validator::make($rows[0], array_filter($rules, static fn (string $field): bool => ! str_contains($field, '.*'), ARRAY_FILTER_USE_KEY))->validate();
        Validator::make($rows[0], array_filter($rules, static fn (string $field): bool => str_starts_with($field, 'notes.*'), ARRAY_FILTER_USE_KEY))->validate();
        if (! $values['discovery_complete']) {
            $reasonKeys = array_map(static fn (CrawlDiscoveryReason $reason): string => $reason->note()->key, CrawlDiscoveryReason::cases());
            if (array_intersect(array_column($values['notes'], 'key'), $reasonKeys) === []) {
                throw new \LogicException('Incomplete crawl discovery requires a declared reason.');
            }
        }
        foreach (['evaluated_finding_ids', 'reclassified_finding_ids', 'observed_urls', 'observed_pages', 'discovered_urls', 'urls', 'mixed_content'] as $key) {
            $itemRules = array_filter($rules, static fn (string $field): bool => str_starts_with($field, $key.'.*'), ARRAY_FILTER_USE_KEY);
            for ($offset = 0, $count = count($values[$key] ?? []); $offset < $count; $offset += 1000) {
                Validator::make([$key => array_slice($values[$key], $offset, 1000, true)], $itemRules)->validate();
            }
        }
        DB::transaction(function () use ($context, $values): void {
            $query = DB::table('crawl_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken);
            $existing = $query->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted crawl artifact.');
            }
            $query->delete();
            $id = DB::table('crawl_snapshots')->insertGetId([
                'site_id' => $context->siteId, 'check_id' => $context->checkId, 'run_id' => $context->runId, 'claim_token' => $context->claimToken,
                'truncated' => $values['truncated'], 'page_cap_reached' => $values['page_cap_reached'] ?? false, 'time_limit_reached' => $values['time_limit_reached'] ?? false, 'resource_cap_reached' => $values['resource_cap_reached'] ?? false, 'observed_urls' => json_encode($values['observed_urls'], JSON_THROW_ON_ERROR),
                'observed_pages' => json_encode($values['observed_pages'], JSON_THROW_ON_ERROR),
                'discovered_urls' => json_encode($values['discovered_urls'], JSON_THROW_ON_ERROR), 'discovery_complete' => $values['discovery_complete'],
                'site_url' => $values['site_url'], 'target_fingerprint' => $values['target_fingerprint'], 'config_fingerprint' => $values['config_fingerprint'], 'comparison_key' => $values['comparison_key'] ?? null, 'collected_at' => $values['collected_at'], 'settings' => json_encode($values['settings'], JSON_THROW_ON_ERROR),
                'evaluated_finding_ids' => json_encode($values['evaluated_finding_ids'], JSON_THROW_ON_ERROR),
                'reclassified_finding_ids' => ($values['reclassified_finding_ids'] ?? null) === null ? null : json_encode($values['reclassified_finding_ids'], JSON_THROW_ON_ERROR),
                'classification_policy_version' => $values['classification_policy_version'],
            ]);
            foreach (['urls' => 'crawl_findings', 'mixed_content' => 'mixed_content_findings'] as $key => $table) {
                foreach (array_chunk($values[$key], 1000) as $batch) {
                    DB::table($table)->insert(array_map(static fn (array $row): array => [...$row, 'snapshot_id' => $id], $batch));
                }
            }
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        DB::transaction(function () use ($run, $claimToken): void {
            $query = DB::table('crawl_snapshots')->where('run_id', $run->id)->where('claim_token', $claimToken);
            $row = $query->lockForUpdate()->first();
            // Engine errors and no-observation attempts legitimately have no staged artifact.
            if ($row === null) {
                return;
            }
            /** @var \stdClass&object{run_ended_at: string|null} $row */
            if ($row->run_ended_at !== null && ! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                throw new \LogicException('Crawl attempt finalized with a different timestamp.');
            }
            $query->update(['run_ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);
        });
    }

    /** @return array{run_id: int, artifact: CrawlArtifact, finding_ids: list<string>}|null */
    public function baseline(int $checkId): ?array
    {
        $row = DB::table('crawl_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')->orderByDesc('run_ended_at')->orderByDesc('run_id')->first();

        /** @var (\stdClass&object{id: int, run_id: int, site_url: string, target_fingerprint: string, config_fingerprint: string|null, collected_at: string, truncated: bool, page_cap_reached: bool, time_limit_reached: bool, resource_cap_reached: bool, observed_urls: string, observed_pages: string, discovered_urls: string, discovery_complete: bool, settings: string, evaluated_finding_ids: string, classification_policy_version: int|null})|null $row */
        if ($row === null) {
            return null;
        }
        /** @var list<string> $ids */
        $ids = json_decode($row->evaluated_finding_ids, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'artifact' => $this->artifact($row), 'finding_ids' => $ids];
    }

    /** @param list<string> $identities
     * @return list<CrawlFinding>|null Null means this exact accepted artifact is no longer retained or is ambiguous.
     */
    public function mailFindings(int $checkId, RunReference $run, array $identities): ?array
    {
        $snapshots = DB::table('crawl_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->limit(2)->get(['id', 'classification_policy_version']);
        if ($snapshots->count() > 1) {
            Log::error('Ambiguous retained crawl mail artifact.', ['check_id' => $checkId, 'run_id' => $run->id, 'ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);

            return null;
        }
        $snapshot = $snapshots->first();
        if ($snapshot === null) {
            return null;
        }
        /** @var \stdClass&object{id: int, classification_policy_version: int|null} $snapshot */
        $legacy = LinkClassificationPolicy::legacySql($snapshot->classification_policy_version);
        $rows = DB::table('crawl_findings')->where('snapshot_id', $snapshot->id)
            ->select(['crawled_url', 'relative_crawled_url', 'status_code', 'found_on_url', 'relative_found_on_url', 'link_text', 'type', 'internal'])
            ->addSelect(LinkClassificationPolicy::labelSql($legacy))
            ->whereIn(DB::raw("encode(sha256(replace(crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex')"), array_slice($identities, 0, 20))
            ->whereRaw(LinkClassificationPolicy::brokenSql('', $legacy))->orderBy('id')->limit(5)->get();

        return array_values($rows->map(static fn (\stdClass $row): CrawlFinding => CrawlFinding::from((array) $row))->all());
    }

    /**
     * Mixed-content rows of exactly the referenced accepted crawl, at most five, preferring the event's new identities.
     * Null means raw retention removed that crawl (or it is ambiguous); an empty list means it holds no such finding.
     *
     * @param  list<string>  $identities
     * @return list<MixedContentFinding>|null
     */
    public function mailMixedContent(int $checkId, RunReference $run, array $identities): ?array
    {
        $snapshots = DB::table('crawl_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->limit(2)->get(['id']);
        if ($snapshots->count() > 1) {
            Log::error('Ambiguous retained crawl mail artifact.', ['check_id' => $checkId, 'run_id' => $run->id, 'ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);

            return null;
        }
        $snapshot = $snapshots->first();
        if ($snapshot === null) {
            return null;
        }
        /** @var \stdClass&object{id: int} $snapshot */
        // Identity is MixedContentFinding::identity(); new findings sort first, then by row order.
        $identity = "encode(sha256(replace(element_name || '|' || mixed_content_url || '|' || found_on_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";
        $query = DB::table('mixed_content_findings')->where('snapshot_id', $snapshot->id)
            ->select(['element_name', 'mixed_content_url', 'found_on_url', 'source_url']);
        $new = array_slice($identities, 0, 20);
        if ($new !== []) {
            $query->orderByRaw('CASE WHEN '.$identity.' IN ('.implode(', ', array_fill(0, count($new), '?')).') THEN 0 ELSE 1 END', $new);
        }
        $rows = $query->orderBy('id')->limit(5)->get();

        return array_values($rows->map(static fn (\stdClass $row): MixedContentFinding => MixedContentFinding::from((array) $row))->all());
    }

    public function findings(int $checkId, bool $mixed): CrawlFindingsData
    {
        $query = DB::table('crawl_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at');
        $latest = (clone $query)->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['id', 'run_id', 'run_ended_at', 'time_limit_reached', 'page_cap_reached', 'resource_cap_reached', 'settings', 'classification_policy_version']);
        if ($latest === null) {
            return new CrawlFindingsData($mixed ? CheckKey::MixedContent : CheckKey::BrokenLinks, null, false, false, false, 0, [], null);
        }
        /** @var \stdClass&object{id: int, run_id: int, run_ended_at: string, time_limit_reached: bool, page_cap_reached: bool, resource_cap_reached: bool, settings: string, classification_policy_version: int|null} $latest */
        $legacy = LinkClassificationPolicy::legacySql($latest->classification_policy_version);
        $grammar = DB::connection()->getQueryGrammar();
        $table = $mixed ? 'mixed_content_findings' : 'crawl_findings';
        $findingQuery = DB::table($table)->where('snapshot_id', $latest->id);
        if (! $mixed) {
            $findingQuery->select(['id', 'crawled_url', 'found_on_url', 'link_text', 'status_code', 'type', 'internal', 'current_error_message'])->addSelect(LinkClassificationPolicy::labelSql($legacy))
                ->where(static function (Builder $query): void {
                    $query->where('status_code', '>=', 400)->orWhereNull('status_code');
                })->whereRaw(LinkClassificationPolicy::verifiableSql($legacy));
        }
        $totalFindings = (clone $findingQuery)->count();
        $rows = $findingQuery->orderBy('id')->limit(50)->get();
        $notVerifiable = [];
        $notVerifiableQuery = DB::table('crawl_findings')->where('snapshot_id', $latest->id)
            ->whereRaw(LinkClassificationPolicy::notVerifiableSql('', $legacy));
        if ($mixed) {
            $notVerifiableQuery->where('internal', true)->where('type', 'link');
        }
        $totalNotVerifiable = (clone $notVerifiableQuery)->count();
        foreach ($notVerifiableQuery->orderBy('id')->limit(50)->select(['crawled_url', 'status_code', 'found_on_url'])->addSelect(LinkClassificationPolicy::labelSql($legacy))->get() as $row) {
            /** @var \stdClass&object{crawled_url: string, error_message: string, status_code: int|null, found_on_url: string|null} $row */
            $notVerifiable[] = ['url' => $row->crawled_url, 'reason' => $row->error_message, 'status' => $row->status_code, 'source' => $row->found_on_url];
        }
        $firstSeen = [];
        if (! $mixed && $rows->isNotEmpty()) {
            /** @var list<string> $urls */
            $urls = $rows->pluck('crawled_url')->all();
            $firstSeen = $this->firstSeen($checkId, $urls);
        }
        $firstCompleteCrawlAt = $this->firstCompleteCrawlAt($checkId);
        $findings = [];
        foreach ($rows as $row) {
            /** @var \stdClass&object{crawled_url: string, mixed_content_url: string, found_on_url: string|null, link_text: string|null, status_code: int|null, type: string, element_name: string, internal: bool, error_message: string|null, current_error_message: string|null} $row */
            $findings[] = [
                'url' => $mixed ? $row->mixed_content_url : $row->crawled_url,
                'found_on' => $row->found_on_url,
                'link_text' => $mixed ? null : $row->link_text,
                'status_code' => $mixed ? null : $row->status_code,
                'kind' => $mixed ? $row->element_name : $row->type,
                'kind_key' => CrawlFindingValues::kindKey($mixed ? $row->element_name : $row->type, $mixed),
                'internal' => $mixed ? true : $row->internal,
                'reason' => $mixed ? null : $row->error_message,
                'reason_key' => $mixed || $row->error_message === null ? null : CrawlFindingValues::resultKey($row->error_message),
                'broken' => $mixed || (! LinkClassificationPolicy::unchecked($row->error_message) && ! LinkClassificationPolicy::notVerifiable($row->error_message, $row->internal)),
                'first_seen_in_retained_history_at' => $mixed || ! isset($firstSeen[$row->crawled_url]) ? null : CarbonImmutable::parse($firstSeen[$row->crawled_url])->utc()->toIso8601String(),
                'current_reason_key' => $mixed || $row->current_error_message === null ? null : CrawlFindingValues::resultKey($row->current_error_message),
                'after_first_complete_crawl' => ! $mixed && $this->firstSeenAfterFirstCompleteCrawl($firstSeen[$row->crawled_url] ?? null, $firstCompleteCrawlAt),
            ];
        }
        $previous = (clone $query)->where('run_ended_at', '<=', now('UTC')->subDays(7)->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['id', 'run_ended_at', 'classification_policy_version']);
        /** @var (\stdClass&object{id: int, run_ended_at: string, classification_policy_version: int|null})|null $previous */
        $weekly = null;
        if ($previous !== null) {
            $filter = static fn (?int $version): string => $mixed ? '' : ' AND '.LinkClassificationPolicy::brokenSql('', LinkClassificationPolicy::legacySql($version))->getValue($grammar);
            $identity = $mixed ? 'mixed_content_url, element_name, found_on_url' : 'crawled_url';
            $count = static function (int $snapshot, ?int $version) use ($table, $filter): int {
                /** @var int $total */
                $total = DB::scalar("SELECT count(*) FROM {$table} WHERE snapshot_id = ?{$filter($version)}", [$snapshot]);

                return $total;
            };
            $difference = static function (int $left, ?int $leftVersion, int $right, ?int $rightVersion, bool $excludeReclassified = false) use ($table, $filter, $identity, $mixed, $grammar): int {
                $bindings = [$left, $right];
                $reclassified = '';
                if ($excludeReclassified && ! $mixed) {
                    // A protected URL changed classification; it was not verified fixed.
                    $reclassified = " UNION SELECT {$identity} FROM {$table} WHERE snapshot_id = ? AND ".LinkClassificationPolicy::notVerifiableSql('', LinkClassificationPolicy::legacySql($rightVersion))->getValue($grammar);
                    $bindings[] = $right;
                }
                /** @var int $total */
                $total = DB::scalar(
                    "SELECT count(*) FROM (SELECT {$identity} FROM {$table} WHERE snapshot_id = ?{$filter($leftVersion)} EXCEPT (SELECT {$identity} FROM {$table} WHERE snapshot_id = ?{$filter($rightVersion)}{$reclassified})) AS changed",
                    $bindings,
                );

                return $total;
            };
            $latestVersion = $latest->classification_policy_version;
            $previousVersion = $previous->classification_policy_version;
            $weekly = [
                'current' => $count($latest->id, $latestVersion), 'previous' => $count($previous->id, $previousVersion),
                'new' => $difference($latest->id, $latestVersion, $previous->id, $previousVersion), 'fixed' => $difference($previous->id, $previousVersion, $latest->id, $latestVersion, true),
                'since' => CarbonImmutable::parse($previous->run_ended_at)->utc()->toIso8601String(),
            ];
        }
        /** @var array{max_pages?: int} $settings */
        $settings = json_decode($latest->settings, true, flags: JSON_THROW_ON_ERROR);

        return new CrawlFindingsData($mixed ? CheckKey::MixedContent : CheckKey::BrokenLinks,
            CarbonImmutable::parse($latest->run_ended_at)->utc()->toIso8601String(), $latest->time_limit_reached, $latest->page_cap_reached, $latest->resource_cap_reached, $settings['max_pages'] ?? 0, $findings, $weekly, new RunReference($latest->run_id, CarbonImmutable::parse($latest->run_ended_at)), $totalFindings, $notVerifiable, $totalNotVerifiable);
    }

    public function exportFindings(CheckKey $type, int $checkId, RunReference $run): ?iterable
    {
        if (! in_array($type, [CheckKey::BrokenLinks, CheckKey::MixedContent], true)) {
            throw new \InvalidArgumentException('Crawl export requires a crawl check.');
        }
        $snapshot = DB::table('crawl_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->sharedLock()->first(['id', 'classification_policy_version']);
        if ($snapshot === null) {
            return null;
        }
        /** @var \stdClass&object{id: int, classification_policy_version: int|null} $snapshot */
        $mixed = $type === CheckKey::MixedContent;
        $query = DB::table($mixed ? 'mixed_content_findings' : 'crawl_findings')->where('snapshot_id', $snapshot->id);
        if (! $mixed) {
            $query->select(['id', 'crawled_url', 'status_code', 'found_on_url', 'link_text', 'internal', 'current_error_message'])
                ->addSelect(LinkClassificationPolicy::labelSql(LinkClassificationPolicy::legacySql($snapshot->classification_policy_version)));
            $query->where(static fn (Builder $query) => $query->where('status_code', '>=', 400)->orWhereNull('status_code'));
        }

        $firstCompleteCrawlAt = $mixed ? null : $this->firstCompleteCrawlAt($checkId);

        return (function () use ($query, $checkId, $mixed, $snapshot, $firstCompleteCrawlAt): \Generator {
            $after = 0;
            do {
                $rows = (clone $query)->where('id', '>', $after)->orderBy('id')->limit(250)->get();
                /** @var list<string> $urls */
                $urls = $mixed ? [] : $rows->pluck('crawled_url')->all();
                $firstSeen = $urls === [] ? [] : $this->firstSeen($checkId, $urls);
                foreach ($rows as $row) {
                    /** @var \stdClass&object{id: int, crawled_url: string, mixed_content_url: string, status_code: int|null, error_message: string|null, found_on_url: string|null, link_text: string|null, internal: bool, current_error_message: string|null} $row */
                    $after = $row->id;
                    $notVerifiable = ! $mixed && LinkClassificationPolicy::notVerifiable($row->error_message, $row->internal);
                    $result = $notVerifiable ? __('crawl.not_verifiable.'.$row->error_message) : ($mixed ? __('checks.mixed_content.label') : ($row->status_code ?? ($row->error_message === null ? __('No response') : __(CrawlFindingValues::resultKey($row->error_message)))));
                    if (! is_string($result) && ! is_int($result)) {
                        throw new \LogicException('Finding result must be text or an HTTP status.');
                    }
                    if (! $mixed && $row->current_error_message !== null) {
                        $result = __('crawl.finding.last_confirmed', ['result' => $result, 'current' => __(CrawlFindingValues::resultKey($row->current_error_message))]);
                        if (! is_string($result)) {
                            throw new \LogicException('Finding result must be text.');
                        }
                    }
                    yield [
                        'url' => $mixed ? $row->mixed_content_url : $row->crawled_url,
                        'result' => (string) $result,
                        'status' => match (true) {
                            $mixed => FindingStatus::Broken,
                            $notVerifiable => FindingStatus::NotVerifiable,
                            LinkClassificationPolicy::unchecked($row->error_message) => FindingStatus::Unchecked,
                            default => FindingStatus::Broken,
                        },
                        'found_on' => $row->found_on_url,
                        'link_text' => $mixed ? null : $row->link_text,
                        'first_seen_at' => $mixed || $notVerifiable ? null : ($firstSeen[$row->crawled_url] ?? null),
                        'after_first_complete_crawl' => ! $mixed && ! $notVerifiable && $this->firstSeenAfterFirstCompleteCrawl($firstSeen[$row->crawled_url] ?? null, $firstCompleteCrawlAt),
                    ];
                }
            } while ($rows->count() === 250);
            if ($mixed) {
                $notVerifiableQuery = DB::table('crawl_findings')->where('snapshot_id', $snapshot->id)
                    ->where('internal', true)->where('type', 'link')
                    ->whereIn('error_message', LinkClassificationPolicy::NOT_VERIFIABLE);
                $after = 0;
                do {
                    $rows = (clone $notVerifiableQuery)->where('id', '>', $after)->orderBy('id')->limit(250)->get(['id', 'crawled_url', 'error_message', 'found_on_url']);
                    foreach ($rows as $row) {
                        /** @var \stdClass&object{id: int, crawled_url: string, error_message: string, found_on_url: string|null} $row */
                        $after = $row->id;
                        yield ['url' => $row->crawled_url, 'result' => __('crawl.not_verifiable.'.$row->error_message), 'status' => FindingStatus::NotVerifiable,
                            'found_on' => $row->found_on_url, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];
                    }
                } while ($rows->count() === 250);
            }
        })();
    }

    /**
     * Findings in the first complete crawl existed before monitoring began, so only later first sightings count as "New".
     * The earliest retained complete snapshot stands in for the first complete crawl: if retention pruned the real one,
     * every finding it held has an even earlier retained first_seen and stays at or below this threshold.
     */
    private function firstSeenAfterFirstCompleteCrawl(?string $firstSeenAt, ?string $firstCompleteCrawlAt): bool
    {
        return $firstSeenAt !== null && $firstCompleteCrawlAt !== null
            && CarbonImmutable::parse($firstSeenAt)->greaterThan(CarbonImmutable::parse($firstCompleteCrawlAt));
    }

    private function firstCompleteCrawlAt(int $checkId): ?string
    {
        /** @var string|null $endedAt */
        $endedAt = DB::table('crawl_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->where('discovery_complete', true)->where('truncated', false)->where('time_limit_reached', false)
            ->min('run_ended_at');

        return $endedAt;
    }

    /** @param list<string> $urls
     * @return array<string, string>
     */
    private function firstSeen(int $checkId, array $urls): array
    {
        // Group and bound the lateral lookup to the requested URLs so PostgreSQL can use the snapshot/hash index rather than repeatedly scanning all findings.
        $findings = DB::table('crawl_findings as retained_finding')->select('retained_finding.crawled_url')
            ->whereColumn('retained_finding.snapshot_id', 'snapshot.id')
            ->whereIn(DB::raw("encode(sha256(replace(retained_finding.crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex')"), array_map(static fn (string $url): string => hash('sha256', $url), $urls))
            ->whereIn('retained_finding.crawled_url', $urls)
            ->whereRaw(LinkClassificationPolicy::brokenSql('retained_finding', 'snapshot.classification_policy_version IS NULL'))->groupBy('retained_finding.crawled_url')->limit(count($urls));

        return DB::table('crawl_snapshots as snapshot')->joinLateral($findings, 'finding')
            ->where('snapshot.check_id', $checkId)->whereNotNull('snapshot.run_ended_at')
            ->selectRaw('finding.crawled_url, min(snapshot.run_ended_at) as first_seen')
            ->groupBy('finding.crawled_url')->get()
            ->mapWithKeys(static function (\stdClass $row): array {
                /** @var \stdClass&object{crawled_url: string, first_seen: string} $row */
                return [$row->crawled_url => $row->first_seen];
            })->all();
    }

    /** @return array{run_id: int, policy_version: int|null, finding_ids: list<string>, reclassified_ids: list<string>, urls: \Generator<CrawlFinding>, mixed_content: \Generator<MixedContentFinding>}|null */
    public function baselineForEvaluation(int $checkId): ?array
    {
        $row = DB::table('crawl_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['id', 'run_id', 'evaluated_finding_ids', 'reclassified_finding_ids', 'classification_policy_version']);
        /** @var (\stdClass&object{id: int, run_id: int, evaluated_finding_ids: string, reclassified_finding_ids: string|null, classification_policy_version: int|null})|null $row */
        if ($row === null) {
            return null;
        }
        /** @var list<string> $ids */
        $ids = json_decode($row->evaluated_finding_ids, true, flags: JSON_THROW_ON_ERROR);
        /** @var list<string> $reclassified */
        $reclassified = $row->reclassified_finding_ids === null ? [] : json_decode($row->reclassified_finding_ids, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'policy_version' => $row->classification_policy_version, 'finding_ids' => $ids, 'reclassified_ids' => $reclassified, 'urls' => $this->urls($row->id, $row->classification_policy_version), 'mixed_content' => $this->mixedContent($row->id)];
    }

    /** Rows of snapshots without a policy version are relabeled under the current policy.
     * @return \Generator<CrawlFinding>
     */
    private function urls(int $snapshotId, ?int $policyVersion): \Generator
    {
        foreach (DB::table('crawl_findings')->where('snapshot_id', $snapshotId)->orderBy('id')->select(['crawled_url', 'relative_crawled_url', 'status_code', 'found_on_url', 'relative_found_on_url', 'link_text', 'type', 'error_message', 'internal', 'current_error_message'])->cursor() as $row) {
            /** @var \stdClass&object{crawled_url: string, relative_crawled_url: string, status_code: int|null, found_on_url: string|null, relative_found_on_url: string|null, link_text: string|null, type: string, error_message: string|null, internal: bool, current_error_message: string|null} $row */
            yield new CrawlFinding($row->crawled_url, $row->relative_crawled_url, $row->status_code, $row->found_on_url, $row->relative_found_on_url, $row->link_text, $row->type,
                $policyVersion === null ? LinkClassificationPolicy::legacyLabel($row->status_code, $row->error_message, $row->internal) : $row->error_message, $row->internal, current_error_message: $row->current_error_message);
        }
    }

    /** @return \Generator<MixedContentFinding> */
    private function mixedContent(int $snapshotId): \Generator
    {
        foreach (DB::table('mixed_content_findings')->where('snapshot_id', $snapshotId)->orderBy('id')->select(['element_name', 'mixed_content_url', 'found_on_url', 'source_url'])->cursor() as $row) {
            /** @var \stdClass&object{element_name: string, mixed_content_url: string, found_on_url: string, source_url: string} $row */
            yield new MixedContentFinding($row->element_name, $row->mixed_content_url, $row->found_on_url, $row->source_url);
        }
    }

    public function latestAccepted(int $siteId): ?CrawlArtifact
    {
        $row = DB::table('crawl_snapshots')->where('site_id', $siteId)->whereNotNull('run_ended_at')->orderByDesc('collected_at')->first();

        /** @var (\stdClass&object{id: int, site_url: string, target_fingerprint: string, config_fingerprint: string|null, collected_at: string, truncated: bool, page_cap_reached: bool, time_limit_reached: bool, resource_cap_reached: bool, observed_urls: string, observed_pages: string, discovered_urls: string, discovery_complete: bool, settings: string, classification_policy_version: int|null})|null $row */
        return $row === null ? null : $this->artifact($row);
    }

    /** @param \stdClass&object{id: int, site_url: string, target_fingerprint: string, config_fingerprint: string|null, collected_at: string, truncated: bool, page_cap_reached: bool, time_limit_reached: bool, resource_cap_reached: bool, observed_urls: string, observed_pages: string, discovered_urls: string, discovery_complete: bool, settings: string, classification_policy_version: int|null} $row */
    private function artifact(\stdClass $row): CrawlArtifact
    {
        $urls = iterator_to_array($this->urls($row->id, $row->classification_policy_version), false);
        $mixed = iterator_to_array($this->mixedContent($row->id), false);

        /** @var array<string, mixed> $settings */
        $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);

        /** @var list<string> $observed */
        $observed = json_decode($row->observed_urls, true, flags: JSON_THROW_ON_ERROR);
        /** @var list<string> $pages */
        $pages = json_decode($row->observed_pages, true, flags: JSON_THROW_ON_ERROR);

        /** @var list<string> $discovered */
        $discovered = json_decode($row->discovered_urls, true, flags: JSON_THROW_ON_ERROR);

        return new CrawlArtifact($row->site_url, CarbonImmutable::parse($row->collected_at)->toIso8601String(), $settings, $urls, $mixed, $row->target_fingerprint, $row->truncated, $observed, $pages, $discovered, $row->discovery_complete, $row->page_cap_reached, $row->time_limit_reached, $row->resource_cap_reached, $row->config_fingerprint, classification_policy_version: LinkClassificationPolicy::VERSION);
    }

    public function discard(int $runId, string $claimToken): void
    {
        DB::table('crawl_snapshots')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }

    public function prune(CarbonImmutable $before): void
    {
        $baselines = DB::table('crawl_snapshots')->selectRaw('DISTINCT ON (check_id) id')->whereNotNull('run_ended_at')->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');
        DB::table('crawl_snapshots')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))->whereNotIn('id', $baselines)->delete();
        DB::table('crawl_collections')->where('collected_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
    }
}
