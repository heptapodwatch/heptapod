<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\PreviousResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SecretRedactor;

final readonly class CrawlEvaluation
{
    public function __construct(private CrawlCollector $collector, private CrawlPersistence $store) {}

    /** @return ($mixed is true ? CheckOutcome|NoResultReason : CheckOutcome) */
    public function execute(CheckContext $context, bool $mixed): CheckOutcome|NoResultReason
    {
        $secrets = array_column($context->crawlerHeaders, 'value');
        try {
            $artifact = CrawlArtifact::from($this->collector->ensureFresh($context)->privateStorage())->redact($secrets, [$context->url])->underCurrentPolicy();
        } catch (CrawlStartRedirectOffSite|CrawlStartRedirectOriginChanged $exception) {
            $target = BrowserUrl::boundedEvidenceUrl(SecretRedactor::redact($exception->targetUrl, $secrets, [$context->url]));
            $summary = $exception instanceof CrawlStartRedirectOriginChanged ? 'crawl.start_redirect_credentials_origin' : 'crawl.start_redirect_off_site';

            return new CheckOutcome(RunResult::Errored, $summary, ['start_redirect_target_url' => $target], null,
                error: new CheckError($exception::class, $exception->getMessage()));
        } catch (CrawlTargetUnavailable $exception) {
            if ($context->crawlerHeaders !== [] && in_array($exception->failure->status, [401, 403], true)) {
                return new CheckOutcome(RunResult::Errored, 'crawl.credentials_rejected', ['status' => $exception->failure->status], null,
                    error: new CheckError($exception::class, $exception->getMessage()));
            }

            if ($exception->failure->isPolicyRefusal()) {
                // Link refusals are per-link findings, so the executor no longer sees this one; keep its specific policy cause.
                $cause = $exception->failure->policySummary();

                return new CheckOutcome(RunResult::Errored, $cause['key'], $cause['params'], null,
                    error: new CheckError($exception::class, $exception->getMessage()));
            }

            return new CheckOutcome(RunResult::Errored, 'crawl.start_page_unreachable.'.$exception->failure->kind->value,
                $exception->failure->status === null ? [] : ['status' => $exception->failure->status], null,
                error: new CheckError($exception::class, $exception->getMessage()),
                availabilityFailure: AvailabilityFailure::fromFetchFailure($exception->failure));
        }
        if ($mixed && $artifact->start_page_too_large && $artifact->start_page_final_url === null) {
            return NoResultReason::MixedContentStartPageNotScanned;
        }
        if ($mixed && $artifact->start_page_final_url === null && ! in_array($artifact->not_crawlable_reason, [NotCrawlableReason::RobotsDisallowed, NotCrawlableReason::StartUrlExcluded], true) && (! $artifact->time_limit_reached || $artifact->urls !== [])) {
            throw new \LogicException('A collected start response must retain its final URL.');
        }
        if ($mixed && parse_url($artifact->start_page_final_url ?? $artifact->site_url, PHP_URL_SCHEME) !== 'https') {
            return new CheckOutcome(RunResult::Failed, 'mixed_content.not_https', [], new CrawlMetrics(0, 0, 0, 0, 0, 0));
        }
        // A nofollow root still provides a scanned page for mixed content, but no checked links.
        if ($artifact->not_crawlable_reason instanceof NotCrawlableReason && (! $mixed || $artifact->not_crawlable_reason !== NotCrawlableReason::Nofollow)) {
            $summary = 'crawl.not_crawlable.'.$artifact->not_crawlable_reason->value;
            if (! $context->previousResult instanceof PreviousResult) {
                $summary .= '.not_checked';
            }

            // No crawl means no replacement baseline and no proof that an open finding incident recovered.
            // An unchecked start page cannot recover even a pure Check-error incident.
            return new CheckOutcome(RunResult::Succeeded, $summary, [], new CrawlMetrics(0, 0, 0, 0, 0, 0),
                new CrawlEvidence([], false, 0, false, false, $this->pageCap($artifact), 0, 0, 0, notes: [...array_filter($artifact->notes, static fn (CheckRunPresentationPartData $note): bool => $note->key === 'crawl.collection_reused'), new CheckRunPresentationPartData($summary)], not_verifiable_count: AutomatedCheckBlockReason::tryFrom($artifact->not_crawlable_reason->value) === null ? 0 : 1), incomplete: true, verifiedNothing: true);
        }
        if ($mixed && ! $artifact->time_limit_reached && ! in_array($artifact->site_url, $artifact->observed_pages, true)) {
            return NoResultReason::MixedContentStartPageNotScanned;
        }
        foreach ([...$artifact->urls, ...$artifact->mixed_content] as $finding) {
            $finding->carried_forward = false;
        }
        $previous = $this->store->baselineForEvaluation($context->checkId);
        $visible = clone $artifact;
        $discovered = array_fill_keys($artifact->discovered_urls, true);
        $previousInternalAccepted = false;
        $retainedMixedFindings = false;
        if ($previous !== null) {
            $previousIds = array_fill_keys($previous['finding_ids'], true);
            $acceptedIds = [];
            $knownUrls = [];
            $unconfirmedUrls = [];
            $brokenUrls = [];
            $retained = [];
            foreach ($artifact->urls as $finding) {
                $knownUrls[$finding->crawled_url] = true;
                if ($finding->broken()) {
                    $brokenUrls[$finding->crawled_url] = true;
                }
                // A refused or doubtful external observation does not prove that a confirmed broken link was fixed.
                if (LinkClassificationPolicy::unchecked($finding->error_message) || AutomatedCheckBlockReason::tryFrom($finding->error_message ?? '') instanceof AutomatedCheckBlockReason
                    || LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal)) {
                    $unconfirmedUrls[$finding->crawled_url] = $finding->error_message;
                }
            }
            foreach ($previous['urls'] as $finding) {
                $accepted = ! $mixed && isset($previousIds[$this->findingIdentity($finding)]);
                $finding->redact($secrets, [$context->url]);
                if ($accepted) {
                    $id = $this->findingIdentity($finding);
                    $acceptedIds[$id] = true;
                    $previousInternalAccepted = $previousInternalAccepted || $finding->internal;
                }
                if ($finding->broken() && (($artifact->time_limit_reached && ! isset($brokenUrls[$finding->crawled_url])) || ((! $artifact->discovery_complete || isset($discovered[$finding->crawled_url])) && (! isset($knownUrls[$finding->crawled_url]) || isset($unconfirmedUrls[$finding->crawled_url])) && (! in_array($finding->crawled_url, $artifact->observed_urls, true) || isset($unconfirmedUrls[$finding->crawled_url]))))) {
                    $finding->carried_forward = true;
                    // Provenance describes this crawl only; a link it did not observe has no current observation.
                    $finding->current_error_message = $unconfirmedUrls[$finding->crawled_url] ?? null;
                    $artifact->urls[] = $finding;
                    $retained[spl_object_id($finding)] = true;
                }
            }
            // One record per link: a retained confirmed failure carries the current unconfirmed observation instead of a second row.
            $merged = [];
            foreach ($artifact->urls as $finding) {
                if (isset($retained[spl_object_id($finding)]) && $finding->current_error_message !== null) {
                    $merged[$finding->crawled_url] = true;
                }
            }
            $artifact->urls = array_values(array_filter($artifact->urls, static fn (CrawlFinding $finding): bool => isset($retained[spl_object_id($finding)]) || ! isset($merged[$finding->crawled_url])));
            $knownMixed = array_fill_keys($this->identities($artifact, true, []), true);
            foreach ($previous['mixed_content'] as $finding) {
                $accepted = $mixed && isset($previousIds[$this->findingIdentity($finding)]);
                $finding->redact($secrets, [$context->url]);
                if ($accepted) {
                    $acceptedIds[$this->findingIdentity($finding)] = true;
                }
                if (! isset($knownMixed[$finding->identity()]) && ($artifact->time_limit_reached || (! in_array($finding->source_url, $artifact->observed_pages, true) && (! $artifact->discovery_complete || isset($discovered[$finding->source_url]))))) {
                    $finding->carried_forward = true;
                    $artifact->mixed_content[] = $finding;
                    $retainedMixedFindings = true;
                }
            }
            $previous['finding_ids'] = array_keys($acceptedIds);
        }
        $overflow = max(array_map(count(...), [$artifact->urls, $artifact->mixed_content, $artifact->observed_urls, $artifact->observed_pages, $artifact->discovered_urls])) > CrawlPersistence::COMPARISON_LIMIT;
        if ($overflow) {
            $artifact = $visible;
            $artifact->urls = array_slice($artifact->urls, 0, CrawlPersistence::COMPARISON_LIMIT);
            $artifact->mixed_content = array_slice($artifact->mixed_content, 0, CrawlPersistence::COMPARISON_LIMIT);
        }
        $internal = count(array_filter($artifact->urls, static fn (CrawlFinding $url): bool => $url->internal));
        $config = $mixed ? $artifact->settings : array_replace(BrokenLinksCheck::configSchema()->defaults(), $context->config);
        if (! $mixed) {
            /** @var list<string> $whitelist */
            $whitelist = $config['whitelisted_urls'];
            $config['whitelisted_urls'] = array_map(static fn (string $url): string => BrowserUrl::comparableRedacted(SecretRedactor::redact($url, array_column($context->crawlerHeaders, 'value'), [$context->url])), $whitelist);
        }
        /** @var list<string> $whitelistedUrls */
        $whitelistedUrls = $config['whitelisted_urls'];
        $whitelist = new CrawlWhitelist($whitelistedUrls);
        $broken = $this->broken($artifact, $config, $whitelist);
        $brokenInternal = count(array_filter($broken, static fn (CrawlFinding $url): bool => $url->internal));
        $metrics = new CrawlMetrics(count($artifact->urls), $internal, count($artifact->urls) - $internal, $brokenInternal, count($broken) - $brokenInternal, count($artifact->mixed_content));
        $identities = $this->identities($artifact, $mixed, $broken);
        $events = [];
        if (! $overflow && ! $artifact->time_limit_reached && $previous !== null) {
            $new = array_values(array_diff($identities, $previous['finding_ids'], $previous['reclassified_ids']));
            if ($new !== []) {
                $event = $mixed ? 'mixed_content.new_finding' : 'broken_links.new_broken_found';
                $events[] = new AddonEvent($event, $event.':'.$context->checkId.':'.$previous['run_id'].':'.hash('sha256', implode('|', $identities)), 1, ['count' => count($new), 'finding_ids' => array_slice($new, 0, 10), 'truncated' => count($new) > 10], presentationParams: $mixed ? null : ['count' => count($new)]);
            }
        }
        $notVerifiableCount = count(array_filter($artifact->urls, static fn (CrawlFinding $finding): bool => (! $mixed || ($finding->internal && $finding->type === 'link')) && LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal)));
        $notVerifiableReasons = [];
        foreach ($mixed ? [] : $artifact->urls as $finding) {
            if ($finding->error_message !== null && LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal)) {
                $notVerifiableReasons[$finding->error_message] = ($notVerifiableReasons[$finding->error_message] ?? 0) + 1;
            }
        }
        $externalNotVerifiable = $mixed ? [] : array_values(array_filter($artifact->urls, static fn (CrawlFinding $finding): bool => ! $finding->internal && LinkClassificationPolicy::notVerifiable($finding->error_message, false)));
        $externalRefused = array_values(array_filter($externalNotVerifiable, static fn (CrawlFinding $finding): bool => in_array($finding->error_message, LinkClassificationPolicy::REFUSALS, true)));
        $externalUnverified = array_values(array_filter($externalNotVerifiable, static fn (CrawlFinding $finding): bool => ! in_array($finding->error_message, LinkClassificationPolicy::REFUSALS, true)));
        $uncheckedCount = count(array_filter($artifact->urls, static fn (CrawlFinding $finding): bool => LinkClassificationPolicy::unchecked($finding->error_message)));
        // Findings include resources and external URLs, not just pages; do not invent a page denominator.
        $rateLimitedCount = count(array_unique(array_map(static fn (CrawlFinding $finding): string => $finding->crawled_url,
            array_filter($visible->urls, static fn (CrawlFinding $finding): bool => $finding->error_message === AutomatedCheckBlockReason::RateLimited->value))));
        $rateLimited = $rateLimitedCount > 0;
        $result = $mixed ? ($metrics->mixed_content > 0 ? RunResult::Failed : RunResult::Succeeded) : ($brokenInternal > 0 ? RunResult::Failed : (count($broken) > 0 || $uncheckedCount > 0 || $rateLimited ? RunResult::Warning : RunResult::Succeeded));

        if ($overflow && $previous !== null && $previous['finding_ids'] !== []) {
            $failed = $mixed || $previousInternalAccepted;
            if ($failed) {
                $result = RunResult::Failed;
            } elseif ($result === RunResult::Succeeded) {
                $result = RunResult::Warning;
            }
        }

        $pageCap = $this->pageCap($artifact);
        $capReached = $artifact->page_cap_reached;
        $summary = ($mixed ? 'mixed_content.' : 'broken_links.').($artifact->time_limit_reached ? 'incomplete_time_limit' : ($overflow ? 'comparison_limit' : ($capReached ? 'incomplete' : ($artifact->resource_cap_reached ? 'incomplete_resources' : ($artifact->truncated ? ($rateLimited ? 'incomplete_rate_limited_urls' : 'incomplete_other') : $result->value)))));
        $params = $overflow ? ['limit' => CrawlPersistence::COMPARISON_LIMIT] : ($capReached ? ['cap' => $pageCap] : []);
        if (str_ends_with($summary, '.incomplete_rate_limited_urls')) {
            $params = ['count' => $rateLimitedCount];
        }
        if ($mixed && $artifact->not_crawlable_reason === NotCrawlableReason::Nofollow && ! $artifact->truncated && ! $overflow) {
            $summary = 'mixed_content.nofollow.'.($retainedMixedFindings
                ? ($visible->mixed_content !== [] ? 'failed_unverified' : 'unverified')
                : $result->value);
        }

        if ($mixed && $metrics->mixed_content === 0 && $notVerifiableCount > 0 && ! $artifact->truncated && ! $overflow) {
            $summary = 'mixed_content.readable_only';
            $params = ['count' => $notVerifiableCount];
        }

        $observedPages = $mixed ? $artifact->observed_pages : array_diff($artifact->observed_pages, $artifact->nofollow_pages);

        $verifiedNothing = ($artifact->truncated || $overflow) && ($mixed ? $observedPages === [] : ! array_any($visible->urls, static fn (CrawlFinding $finding): bool => $finding->status_code !== null && ! LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal)));

        $confirmedIncompleteFailure = $artifact->truncated && ! $artifact->time_limit_reached && ! $overflow && $result === RunResult::Failed && ! $verifiedNothing;
        /** @var array<string, mixed> $row */
        $row = $artifact->privateStorage();
        $row['evaluated_finding_ids'] = $identities;
        $row['reclassified_finding_ids'] = $this->carriedReclassifiedIds($previous['reclassified_ids'] ?? [], $visible, $identities);
        $row['comparison_key'] = hash('sha256', json_encode([$artifact->target_fingerprint, $artifact->config_fingerprint, $config], JSON_THROW_ON_ERROR));
        if (! $overflow) {
            $this->store->stage($context, 'artifact', [$row]);
        }

        return new CheckOutcome($result, $summary, $params, $metrics, new CrawlEvidence(array_slice($identities, 0, 10), count($identities) > 10, $uncheckedCount, $artifact->discovery_complete, $artifact->truncated, $pageCap, count($observedPages), count($artifact->observed_urls), count($artifact->discovered_urls), $capReached, $artifact->time_limit_reached, $artifact->resource_cap_reached, $overflow, [...$artifact->notes, ...(! $mixed && $whitelist->ignoredInvalid ? [new CheckRunPresentationPartData('broken_links.whitelist_entry_ignored_invalid')] : [])], $notVerifiableCount,
            count($externalUnverified), $this->sharedReason($externalUnverified), count($externalRefused), $this->sharedReason($externalRefused), $notVerifiableReasons), $events, incomplete: $artifact->truncated || $overflow, confirmedIncompleteFailure: $confirmedIncompleteFailure, verifiedNothing: $verifiedNothing);
    }

    /** @param list<CrawlFinding> $findings */
    private function sharedReason(array $findings): ?string
    {
        $reasons = array_values(array_unique(array_map(static fn (CrawlFinding $finding): string => $finding->error_message ?? throw new \LogicException('Not-verifiable finding without a label.'), $findings)));

        return count($reasons) > 1 ? 'mixed' : ($reasons[0] ?? null);
    }

    private function pageCap(CrawlArtifact $artifact): int
    {
        $pageCap = $artifact->settings['max_pages'];
        if (! is_int($pageCap) || $pageCap < 1) {
            throw new \LogicException('Crawl artifact requires a positive page cap.');
        }

        return $pageCap;
    }

    /** @param array<string, mixed> $config
     * @return list<CrawlFinding>
     */
    private function broken(CrawlArtifact $artifact, array $config, CrawlWhitelist $whitelist): array
    {
        return array_values(array_filter($artifact->urls, static function (CrawlFinding $url) use ($artifact, $config, $whitelist): bool {
            if (! $url->broken()) {
                return false;
            }
            if ($url->error_message !== 'invalid URL') {
                if ($whitelist->matches(BrowserUrl::comparableRedacted($url->crawled_url)) || BrowserUrl::isEmailProtection($url->crawled_url, $artifact->site_url)) {
                    return false;
                }
            }

            return $url->internal || $config['check_include_external_links'] === true || $url->error_message === 'invalid URL';
        }));
    }

    /**
     * A reclassified identity stays known only while this crawl sees it as not verifiable again or does not reach it.
     * Broken again (then counted), observed OK, or gone from a complete crawl ends it, so a later failure is new.
     *
     * @param  list<string>  $reclassified
     * @param  list<string>  $identities
     * @return list<string>|null
     */
    private function carriedReclassifiedIds(array $reclassified, CrawlArtifact $crawl, array $identities): ?array
    {
        $counted = array_fill_keys($identities, true);
        $notVerifiable = [];
        foreach ($crawl->urls as $finding) {
            if (LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal) || LinkClassificationPolicy::unchecked($finding->error_message) || $finding->error_message === AutomatedCheckBlockReason::RateLimited->value) {
                $notVerifiable[$this->findingIdentity($finding)] = true;
            }
        }
        $observed = array_fill_keys(array_map(static fn (string $url): string => hash('sha256', $url), $crawl->observed_urls), true);
        $complete = ! $crawl->truncated && ! $crawl->time_limit_reached && $crawl->discovery_complete;
        $carried = array_values(array_filter($reclassified, static fn (string $id): bool => ! isset($counted[$id])
            && (isset($notVerifiable[$id]) || (! isset($observed[$id]) && ! $complete))));

        return $carried === [] ? null : $carried;
    }

    private function findingIdentity(CrawlFinding|MixedContentFinding $finding): string
    {
        return $finding instanceof MixedContentFinding ? $finding->identity() : hash('sha256', $finding->crawled_url);
    }

    /** @param list<CrawlFinding> $broken
     * @return list<string> Hash identities keep query strings, credentials and resource data out of events/evidence.
     */
    private function identities(CrawlArtifact $artifact, bool $mixed, array $broken): array
    {
        $ids = array_map($this->findingIdentity(...), $mixed ? $artifact->mixed_content : $broken);
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
