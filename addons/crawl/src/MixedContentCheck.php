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
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Config;

final readonly class MixedContentCheck implements CheckDetailProvider, CheckHistoryProvider, CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private CrawlEvaluation $evaluation, private CrawlPersistence $store) {}

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'mixed_content.mail_event.check.failing',
            'check.warning' => 'mixed_content.mail_event.check.warning',
            'check.recovered' => 'mixed_content.mail_event.check.recovered',
            'mixed_content.new_finding' => 'mixed_content.mail_event.mixed_content.new_finding',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'mixed_content.new_finding' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        // An errored crawl or a legacy observation has no findings to show; the caller explains a check error.
        if ($run->metrics === null || $run->observationEvidence() === []) {
            return [];
        }
        $total = CrawlMetrics::from($run->metrics)->mixed_content;
        if ($total === 0) {
            return [new NoteBlock(__('mixed_content.mail_no_findings', locale: $locale))];
        }
        $newIds = $event->payload['finding_ids'] ?? [];
        if (! is_array($newIds) || array_any($newIds, static fn (mixed $id): bool => ! is_string($id))) {
            throw new \LogicException('Mixed content event identities must be a list of strings.');
        }
        /** @var list<string> $newIds */
        $rows = $event->run instanceof RunReference ? $this->store->mailMixedContent($event->checkId, $event->run, $newIds) : null;
        if ($rows === null || $rows === []) {
            return [new NoteBlock(__('mixed_content.mail_details_not_retained', locale: $locale))];
        }
        // The table lists the observation's findings; only the event's own identities are marked new, as in the broken-links mail.
        $new = array_flip($newIds);
        $newLabel = __('mixed_content.mail_new', locale: $locale);
        if (! is_string($newLabel)) {
            throw new \LogicException('Mixed content new label must be text.');
        }
        $table = array_map(static fn (MixedContentFinding $finding): array => [
            new Cell(__(CrawlFindingValues::kindKey($finding->element_name, true), locale: $locale), keepOnOneLine: true),
            new Cell($finding->mixed_content_url, mono: true, tone: MailTone::Failed, badge: isset($new[$finding->identity()]) ? $newLabel : null),
            new Cell($finding->found_on_url, mono: true)], $rows);
        $more = $total > count($rows) ? __('mixed_content.mail_more', ['count' => $total - count($rows)], $locale) : null;
        if ($more !== null && ! is_string($more)) {
            throw new \LogicException('Mixed content more label must be text.');
        }
        $blocks = [];
        $newCount = $event->payload['count'] ?? null;
        if ($event->type === 'mixed_content.new_finding') {
            if (! is_int($newCount) || $newCount < 1) {
                throw new \LogicException('A new mixed content event must retain its new-finding count.');
            }
            $blocks[] = new NoteBlock(trans_choice('mixed_content.mail_new_since_crawl', $newCount, ['count' => $newCount], $locale), MailTone::Failed);
        }
        $blocks[] = new TableBlock(__('mixed_content.mail_findings', locale: $locale), [__('mixed_content.mail_kind', locale: $locale),
            __('mixed_content.mail_url', locale: $locale), __('mixed_content.mail_found_on', locale: $locale)], $table, $more);

        return $blocks;
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('mixed_content.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        return new CheckDefinition(CheckKey::MixedContent, 'checks.mixed_content.label', 'shield-alert', 'checks-crawl', Config::integer('heptapod.crawl.timeout_seconds'), Config::integer('heptapod.crawl.interval_seconds'), 1, ExecutionMode::Derived, new Schema([]), CrawlMetrics::schema(), ['mixed_content.new_finding'], requiresHttps: true, detailDataClass: CrawlSnapshot::class);
    }

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?CrawlSnapshot
    {
        return CrawlSnapshot::fromRun(CheckKey::MixedContent, $latestRun);
    }

    public function history(int $checkId, bool $canViewCapabilities = false): CrawlFindingsData
    {
        return $this->store->findings($checkId, true);
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        return CrawlEvidence::presentCollection($run, $this->presentFindings($run));
    }

    private function presentFindings(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $observation = $run->observationEvidence();
        if ($run->metrics === null || $observation === []) {
            return null;
        }
        $evidence = CrawlEvidence::hydrateObservation($observation);
        foreach ($evidence->notes as $note) {
            if (str_starts_with($note->key, 'crawl.not_crawlable.bot_protection') || str_starts_with($note->key, 'crawl.not_crawlable.rate_limited')) {
                $part = $note;

                return new CheckRunPresentationData([$part], history_label: $part);
            }
        }
        if ($evidence->not_verifiable_count < 0) {
            throw new \LogicException('Crawl presentation counters are invalid.');
        }
        // Error and legacy observations can lack crawl metrics; only a recorded zero permits a limit-only headline.
        if (($run->metrics['mixed_content'] ?? null) === 0 && ($evidence->crawl_truncated || $evidence->comparison_limit_reached)) {
            return new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams)]);
        }
        $rateLimited = str_ends_with($run->summary, '.incomplete_rate_limited_urls');
        if ($evidence->not_verifiable_count === 0 && ! $rateLimited) {
            return null;
        }
        CrawlMetrics::schema()->validateValues($run->metrics);
        $metrics = CrawlMetrics::from($run->metrics);
        $parts = $metrics->mixed_content === 0 ? [] : [new CheckRunPresentationPartData('mixed_content.presentation.resources', ['count' => $metrics->mixed_content])];
        if ($evidence->not_verifiable_count > 0) {
            $parts[] = new CheckRunPresentationPartData($metrics->mixed_content === 0 ? 'mixed_content.presentation.readable_only' : 'mixed_content.presentation.not_verifiable', ['count' => $evidence->not_verifiable_count]);
        }
        if ($rateLimited) {
            $parts[] = new CheckRunPresentationPartData('mixed_content.presentation.rate_limited', $run->summaryParams);
        }

        return new CheckRunPresentationData($parts);
    }

    public function execute(CheckContext $context): CheckOutcome|NoResultReason
    {
        return $this->evaluation->execute($context, true);
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }
}
