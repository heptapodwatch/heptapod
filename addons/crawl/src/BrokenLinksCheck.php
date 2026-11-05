<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Carbon\CarbonImmutable;
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
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Config;

final readonly class BrokenLinksCheck implements CheckDetailProvider, CheckHistoryProvider, CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private CrawlEvaluation $evaluation, private CrawlPersistence $store) {}

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'broken_links.mail_event.check.failing',
            'check.warning' => 'broken_links.mail_event.check.warning',
            'check.recovered' => 'broken_links.mail_event.check.recovered',
            'broken_links.new_broken_found' => 'broken_links.mail_event.broken_links.new_broken_found',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'broken_links.new_broken_found' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $observation = $run->observationEvidence();
        if ($run->metrics === null || $observation === []) {
            return [];
        }
        $metrics = CrawlMetrics::from($run->metrics);
        $total = $metrics->broken_internal + $metrics->broken_external;
        if ($total === 0) {
            return [new NoteBlock(__('broken_links.mail_no_broken', locale: $locale))];
        }
        $evidence = CrawlEvidence::hydrateObservation($observation);
        $retainedIds = $event->payload['finding_ids'] ?? [];
        if (! is_array($retainedIds)) {
            throw new \LogicException('Broken link event identities must be a list.');
        }
        $newIds = [];
        foreach ($retainedIds as $id) {
            if (! is_string($id)) {
                throw new \LogicException('Broken link event identities must be strings.');
            }
            $newIds[] = $id;
        }
        $ids = array_values(array_unique([...$evidence->finding_ids, ...$newIds]));
        $rows = $event->run instanceof RunReference ? $this->store->mailFindings($event->checkId, $event->run, $ids) : null;
        if ($rows === null || $rows === []) {
            return [new NoteBlock(__('broken_links.mail_details_not_retained', locale: $locale))];
        }
        if ($event->type === 'broken_links.new_broken_found') {
            $lines = array_map(static fn (CrawlFinding $finding): array => [
                'sign' => in_array(hash('sha256', $finding->crawled_url), $newIds, true) ? '+' : ' ',
                'text' => $finding->relative_crawled_url.' · '.($finding->status_code === null ? ($finding->error_message === null ? __('broken_links.mail_no_status', locale: $locale) : __(CrawlFindingValues::resultKey($finding->error_message), locale: $locale)) : (string) $finding->status_code).($finding->relative_found_on_url === null ? '' : ' · '.$finding->relative_found_on_url),
                'tone' => MailTone::Failed,
            ], $rows);
            $blocks = [new DiffBlock(__('broken_links.mail_since_crawl', locale: $locale), $lines)];
            if ($total > count($rows)) {
                $blocks[] = new NoteBlock(__('broken_links.mail_more', ['count' => $total - count($rows)], $locale));
            }

            return $blocks;
        }
        $table = array_map(static fn (CrawlFinding $finding): array => [new Cell($finding->relative_crawled_url, mono: true),
            new Cell($finding->status_code === null ? ($finding->error_message === null ? __('broken_links.mail_no_status', locale: $locale) : __(CrawlFindingValues::resultKey($finding->error_message), locale: $locale)) : (string) $finding->status_code, mono: true, tone: MailTone::Failed),
            new Cell($finding->relative_found_on_url ?? '—', mono: true)], $rows);

        $more = $total > count($rows) ? __('broken_links.mail_more', ['count' => $total - count($rows)], $locale) : null;
        if ($more !== null && ! is_string($more)) {
            throw new \LogicException('Broken link more label must be text.');
        }

        return [new TableBlock(__('broken_links.mail_findings', locale: $locale), [__('broken_links.mail_url', locale: $locale), __('broken_links.mail_result', locale: $locale), __('broken_links.mail_found_on', locale: $locale)], $table, $more)];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('broken_links.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(CheckKey::BrokenLinks, 'checks.broken_links.label', 'unlink', 'checks-crawl', Config::integer('heptapod.crawl.timeout_seconds'), Config::integer('heptapod.crawl.interval_seconds'), 1, ExecutionMode::Poll, self::configSchema(), CrawlMetrics::schema(), ['broken_links.new_broken_found'], detailDataClass: CrawlSnapshot::class);
    }

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?CrawlSnapshot
    {
        return CrawlSnapshot::fromRun(CheckKey::BrokenLinks, $latestRun);
    }

    public function history(int $checkId, bool $canViewCapabilities = false): CrawlFindingsData
    {
        return $this->store->findings($checkId, false);
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        return CrawlEvidence::presentCollection($run, $this->presentFindings($run));
    }

    private function presentFindings(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        if ($run->result === RunResult::Errored) {
            return null;
        }
        $change = $run->eventPayload('broken_links.new_broken_found');
        $changePart = null;
        if ($change !== null) {
            $count = $change['count'] ?? null;
            if (! is_int($count) || $count < 1) {
                throw new \LogicException('Broken link change requires its retained count.');
            }
            $total = $run->metrics === null ? null : CrawlMetrics::from($run->metrics);

            $changePart = new CheckRunPresentationPartData($total instanceof CrawlMetrics ? 'broken_links.presentation.new' : 'broken_links.presentation.new_unretained',
                $total instanceof CrawlMetrics ? ['count' => $count, 'total' => $total->broken_internal + $total->broken_external] : ['count' => $count],
                shortKey: $total instanceof CrawlMetrics ? 'broken_links.presentation.new_short' : 'broken_links.presentation.new_unretained');
            if ($run->result === RunResult::Succeeded) {
                return new CheckRunPresentationData([$changePart]);
            }
            if (! $total instanceof CrawlMetrics) {
                return new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams)], changes: [$changePart]);
            }
        }
        if ($run->metrics === null || (! array_key_exists('broken_internal', $run->metrics) && ! array_key_exists('broken_external', $run->metrics))) {
            return null;
        }
        if (in_array($run->summary, ['broken_links.no_verified_broken_one', 'broken_links.no_verified_broken_other'], true)) {
            $part = new CheckRunPresentationPartData($run->summary, $run->summaryParams);

            return new CheckRunPresentationData([$part], history_label: $part);
        }
        CrawlMetrics::schema()->validateValues($run->metrics);
        $metrics = CrawlMetrics::from($run->metrics);
        $observation = $run->observationEvidence();
        $evidence = $observation === [] ? null : CrawlEvidence::hydrateObservation($observation);
        $reasons = [];
        $details = [];
        foreach (['internal' => $metrics->broken_internal, 'external' => $metrics->broken_external] as $kind => $count) {
            if ($count > 0) {
                $reasons[] = ['key' => $kind, 'severity' => $kind === 'internal' ? RunResult::Failed : RunResult::Warning,
                    'occurrences' => $count, 'part' => new CheckRunPresentationPartData('broken_links.presentation.'.$kind, ['count' => $count])];
            }
        }
        if ($evidence instanceof CrawlEvidence) {
            foreach ($evidence->notes as $note) {
                if (str_starts_with($note->key, 'crawl.not_crawlable.bot_protection') || str_starts_with($note->key, 'crawl.not_crawlable.rate_limited')) {
                    $part = $note;

                    return new CheckRunPresentationData([$part], history_label: $part);
                }
            }
            if ($evidence->unchecked_count < 0 || $evidence->not_verifiable_count < 0 || $evidence->page_cap < 1) {
                throw new \LogicException('Crawl presentation counters are invalid.');
            }
            $details = $this->notVerifiableDetails($evidence);
            if ($evidence->unchecked_count > 0) {
                $reasons[] = ['key' => 'unchecked', 'severity' => RunResult::Warning, 'occurrences' => $evidence->unchecked_count,
                    'part' => new CheckRunPresentationPartData('broken_links.presentation.unchecked', ['count' => $evidence->unchecked_count], ['subject' => 'broken_links.presentation.link_subject'])];
            }
            foreach (['page_limit' => $evidence->page_cap_reached, 'resource_limit' => $evidence->resource_cap_reached,
                'time_limit' => $evidence->time_limit_reached, 'comparison_limit' => $evidence->comparison_limit_reached] as $kind => $reached) {
                if ($reached) {
                    $reasons[] = ['key' => $kind, 'severity' => $kind === 'time_limit' ? RunResult::Errored : RunResult::Warning,
                        'occurrences' => 1, 'part' => new CheckRunPresentationPartData('broken_links.presentation.'.$kind,
                            $kind === 'page_limit' ? ['cap' => $evidence->page_cap] : [])];
                }
            }
        }

        if (str_ends_with($run->summary, '.incomplete_rate_limited_urls')) {
            $reasons[] = ['key' => 'rate_limited', 'severity' => RunResult::Warning, 'occurrences' => 1,
                'part' => new CheckRunPresentationPartData($reasons === [] ? $run->summary : 'broken_links.presentation.rate_limited', $run->summaryParams)];
        }

        if ($reasons === [] && $details === []) {
            return null;
        }
        // Not-verifiable links are no problem state: they stay out of the headline and explain themselves in the details line.
        $presentation = $reasons === [] ? new CheckRunPresentationData([new CheckRunPresentationPartData('broken_links.succeeded')]) : CheckRunPresentationData::forReasons($reasons);
        $remainder = array_slice($presentation->parts, 2);
        $headlineLimit = $remainder !== [] && array_all($remainder, static fn (CheckRunPresentationPartData $part): bool => $part->key === 'broken_links.presentation.unchecked') ? count($presentation->parts) : 2;
        // Keep the operational timeout reason ahead of informational counts in history.
        if ($evidence instanceof CrawlEvidence && $evidence->not_verifiable_count > 0 && ! $evidence->time_limit_reached) {
            $broken = $metrics->broken_internal + $metrics->broken_external;
            $label = new CheckRunPresentationPartData($broken === 0 ? 'broken_links.presentation.not_verifiable' : 'broken_links.history.with_not_verifiable', ['count' => $evidence->not_verifiable_count, 'broken' => $broken]);

            return new CheckRunPresentationData($presentation->parts, history_label: $label, headline_limit: $headlineLimit, changes: $changePart instanceof CheckRunPresentationPartData ? [$changePart] : [], details: $details);
        }

        return new CheckRunPresentationData($presentation->parts, headline_limit: $headlineLimit, changes: $changePart instanceof CheckRunPresentationPartData ? [$changePart] : [], details: $details);
    }

    /** @return list<CheckRunPresentationPartData> The count of not-verifiable links, then their most frequent reasons. */
    private function notVerifiableDetails(CrawlEvidence $evidence): array
    {
        $external = $evidence->external_not_verifiable_count + $evidence->external_refused_count;
        if ($evidence->not_verifiable_count === 0) {
            return [];
        }
        if ($external > $evidence->not_verifiable_count || array_sum($evidence->not_verifiable_reasons) !== ($evidence->not_verifiable_reasons === [] ? 0 : $evidence->not_verifiable_count)) {
            throw new \LogicException('Crawl not-verifiable counters are invalid.');
        }
        $reasons = $evidence->not_verifiable_reasons;
        // Runs before per-reason counts retained one shared label per external group, or "mixed".
        if ($reasons === [] && $external === $evidence->not_verifiable_count) {
            foreach ([[$evidence->external_refused_count, $evidence->external_refused_reason], [$evidence->external_not_verifiable_count, $evidence->external_not_verifiable_reason]] as [$count, $shared]) {
                if (($count > 0) !== ($shared !== null)) {
                    throw new \LogicException('Crawl not-verifiable counters are invalid.');
                }
                if ($count > 0 && $shared !== 'mixed') {
                    $reasons[$shared ?? throw new \LogicException('Missing not-verifiable reason.')] = $count;
                }
            }
            if (array_sum($reasons) !== $evidence->not_verifiable_count) {
                $reasons = [];
            }
        }
        $grouped = [];
        foreach ($reasons as $label => $count) {
            $group = in_array($label, LinkClassificationPolicy::REFUSALS, true) ? 'refused' : $label;
            $grouped[$group] = ($grouped[$group] ?? 0) + $count;
        }
        $reasons = $grouped;
        arsort($reasons);
        $details = [new CheckRunPresentationPartData($external === $evidence->not_verifiable_count ? 'broken_links.presentation.unverified_external' : 'broken_links.presentation.not_verifiable', ['count' => $evidence->not_verifiable_count])];
        foreach (array_slice($reasons, 0, count($reasons) > 3 ? 2 : 3, true) as $label => $count) {
            $details[] = $label === 'refused'
                ? new CheckRunPresentationPartData('broken_links.presentation.unverified_refused', ['count' => $count])
                : new CheckRunPresentationPartData('broken_links.presentation.unverified_reason', ['count' => $count], ['reason' => CrawlFindingValues::notVerifiableShortKey($label)]);
        }
        if (count($reasons) > 3) {
            $details[] = new CheckRunPresentationPartData('broken_links.presentation.unverified_other_reasons', ['count' => array_sum(array_slice($reasons, 2))]);
        }

        return $details;
    }

    public static function configSchema(): Schema
    {
        /** @var array<string, array<array-key, mixed>|string|int|float|bool|null> $defaults */
        $defaults = Config::array('heptapod.crawl.defaults');
        $fields = [];
        foreach (['exclude_patterns', 'do_not_crawl_urls', 'force_crawl_urls', 'whitelisted_urls'] as $key) {
            $fields[$key] = new Field(FieldType::List, ['sometimes', 'max:1000'], items: new Field(FieldType::String, $key === 'whitelisted_urls' ? ['required', 'crawl_whitelist_url', 'max:1024'] : ['required', 'max:1024']), presentation: new FieldPresentation('crawl.settings.'.$key), default: $defaults[$key], trimmedStrings: $key !== 'whitelisted_urls');
        }
        foreach (['check_include_external_links', 'new_links_only', 'respect_robots'] as $key) {
            $fields[$key] = new Field(FieldType::Boolean, ['sometimes'], presentation: new FieldPresentation('crawl.settings.'.$key), default: $defaults[$key]);
        }
        $fields['types'] = new Field(FieldType::List, ['sometimes', 'min:1', 'max:5'], items: new Field(FieldType::String, ['required', 'in:'.implode(',', array_keys(CrawlFindingValues::ResourceKinds))]), presentation: new FieldPresentation('crawl.settings.types', options: CrawlFindingValues::ResourceKinds), default: $defaults['types']);
        $fields['max_depth'] = new Field(FieldType::Integer, ['sometimes', 'between:0,20'], presentation: new FieldPresentation('crawl.settings.max_depth'), default: $defaults['max_depth']);
        $fields['max_pages'] = new Field(FieldType::Integer, ['sometimes', 'between:1,10000'], presentation: new FieldPresentation('crawl.settings.max_pages'), default: $defaults['max_pages']);

        return new Schema($fields);
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        return $this->evaluation->execute($context, false);
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }
}
