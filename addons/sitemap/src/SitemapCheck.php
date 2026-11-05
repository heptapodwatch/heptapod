<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Carbon\CarbonImmutable;
use DOMDocument;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\CheckRunPresenter;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Findings\FindingsCoverage;
use Heptapod\Contracts\Http\AutomatedCheckBlock;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\SitemapLocations;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\SecretRedactor;
use Illuminate\Support\Facades\Config;
use League\Uri\Uri;
use Spatie\Crawler\UrlParsers\SitemapUrlParser;

final readonly class SitemapCheck implements CheckDetailProvider, CheckRunPresenter, CheckType, MailEvidenceProvider
{
    public function __construct(private OutboundHttp $http, private SitemapPersistence $store, private SitemapDocumentReader $documents) {}

    public function mailEventPhrases(): array
    {
        return [
            'check.failing' => 'sitemap.mail_event.check.failing',
            'check.warning' => 'sitemap.mail_event.check.warning',
            'check.recovered' => 'sitemap.mail_event.check.recovered',
            'sitemap.issues_changed' => 'sitemap.mail_event.sitemap.issues_changed',
        ];
    }

    public function mailEventTones(): array
    {
        return [
            'incident.opened' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.cause_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError],
            'incident.resolved' => ['succeeded' => MailTone::Recovered, 'warning' => MailTone::Recovered],
            'check.result_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Recovered],
            'sitemap.issues_changed' => ['failed' => MailTone::Failed, 'errored-or-timed-out' => MailTone::CheckError, 'warning' => MailTone::Warning, 'succeeded' => MailTone::Changed],
        ];
    }

    public function mailEvidence(CheckRunPresentationInput $run, CarbonImmutable $observedAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        $evidence = $run->observationEvidence();
        if ($evidence === []) {
            return [];
        }
        $snapshot = SitemapSnapshot::from($evidence);
        if ($snapshot->totalIssuesCount === 0) {
            return [new NoteBlock(__($run->summary, $run->summaryParams, $locale))];
        }
        $rows = [];
        foreach (array_slice($snapshot->issues, 0, 5) as $issue) {
            $status = $issue['status'] ?? null;
            $xmlProblem = SitemapXmlProblem::presentation($issue);
            $reason = $xmlProblem instanceof CheckRunPresentationPartData ? __($xmlProblem->key, $xmlProblem->localizedParams($locale), $locale) : ($status === null ? __(isset($issue['kind']) ? 'sitemap.status.'.$issue['kind'] : $issue['type'], locale: $locale) : (string) $status);
            $rows[] = [new Cell($issue['url'], mono: true), new Cell($reason, mono: true, tone: MailTone::Failed)];
        }
        if ($rows === []) {
            return [new NoteBlock(__('sitemap.mail_details_not_retained', locale: $locale))];
        }

        $more = $snapshot->totalIssuesCount > count($rows) ? __('sitemap.mail_more', ['count' => $snapshot->totalIssuesCount - count($rows)], $locale) : null;
        if ($more !== null && ! is_string($more)) {
            throw new \LogicException('Sitemap more label must be text.');
        }

        return [new TableBlock(__('sitemap.mail_issues', locale: $locale), [__('sitemap.mail_url', locale: $locale), __('sitemap.mail_result', locale: $locale)], $rows, $more),
            new NoteBlock(trans_choice('sitemap.mail_total', $snapshot->totalUrlCount, ['count' => $snapshot->totalUrlCount], $locale))];
    }

    public function mailRecoveryEvidence(CheckRunPresentationInput $opening, CarbonImmutable $openedAt, ?CheckRunPresentationInput $recovery, CarbonImmutable $recoveredAt, string $locale, string $timezone, MailEventFacts $event): array
    {
        return $recovery instanceof CheckRunPresentationInput
            ? $this->mailEvidence($recovery, $recoveredAt, $locale, $timezone, $event)
            : [new NoteBlock(__('sitemap.mail_details_not_retained', locale: $locale))];
    }

    public function definition(): CheckDefinition
    {
        // PHP turns numeric-string keys into integers; the stored wire values remain the strings 0, 100, 500, 1000 and max.
        /** @var array<string, string> $urlsPerRunChoices */
        $urlsPerRunChoices = ['0' => 'sitemap.urls_per_run.0', '100' => 'sitemap.urls_per_run.100', '500' => 'sitemap.urls_per_run.500', '1000' => 'sitemap.urls_per_run.1000', 'max' => 'sitemap.urls_per_run.max'];

        return new CheckDefinition(
            CheckKey::Sitemap, 'checks.sitemap.label', 'map', 'checks-crawl', 300, 86400, 1, ExecutionMode::Poll,
            new Schema([
                'path' => new Field(FieldType::String, ['sometimes', 'max:2048', 'regex:~^/(?!/)[^#\r\n]*$~'], presentation: new FieldPresentation('sitemap.path'), default: '/sitemap.xml'),
                'speed' => new Field(FieldType::String, ['sometimes', 'in:default,fast,slow,slowest,fastest'], presentation: new FieldPresentation('sitemap.speed', options: [
                    'default' => 'sitemap.speed.default', 'fast' => 'sitemap.speed.fast', 'slow' => 'sitemap.speed.slow', 'slowest' => 'sitemap.speed.slowest', 'fastest' => 'sitemap.speed.fastest',
                ]), default: 'default'),
                'urls_per_run' => new Field(FieldType::String, ['sometimes', 'in:'.implode(',', array_keys($urlsPerRunChoices))], presentation: new FieldPresentation('sitemap.urls_per_run', options: $urlsPerRunChoices), default: '500'),
            ]),
            new Schema([
                'totalIssuesCount' => new Field(FieldType::Integer, ['required', 'min:0']),
                'totalUrlCount' => new Field(FieldType::Integer, ['required', 'min:0']),
                'hasIssues' => new Field(FieldType::Boolean, ['required']),
            ]),
            emittedEvents: ['sitemap.issues_changed'], detailDataClass: SitemapSnapshot::class,
        );
    }

    public function detail(int $checkId, ?LatestRunEvidence $latestRun = null): ?SitemapSnapshot
    {
        return ! $latestRun instanceof LatestRunEvidence || $latestRun->observationEvidence() === []
            ? null : SitemapSnapshot::from([...$latestRun->observationEvidence(), 'export_run' => $latestRun->run])->bounded();
    }

    public function presentRun(CheckRunPresentationInput $run): ?CheckRunPresentationData
    {
        $evidence = $run->observationEvidence();
        if ($evidence === []) {
            return null;
        }
        if (in_array($run->summary, ['sitemap.no_verified_issues_one', 'sitemap.no_verified_issues_other'], true)) {
            $part = new CheckRunPresentationPartData($run->summary, $run->summaryParams);

            return new CheckRunPresentationData([$part], history_label: $part, names_check: true);
        }
        $snapshot = SitemapSnapshot::from($evidence);
        if ($snapshot->issue_counts === null) {
            return new CheckRunPresentationData([new CheckRunPresentationPartData('sitemap.presentation.legacy_capped',
                ['total' => $snapshot->totalIssuesCount, 'shown' => count($snapshot->issues)])]);
        }
        $reasons = [];
        foreach ($snapshot->issue_counts as $key => $count) {
            $http = str_starts_with($key, 'http_') && $key !== 'http_status';
            $label = $http ? 'http_status' : match ($key) {
                'timeout' => 'timeout',
                'tls' => 'tls',
                'dns' => 'dns',
                'connect' => 'connect',
                'http_status', 'unreachable' => 'unreachable',
                'too_large', 'size_limit' => 'size_limit',
                'private_target' => 'private_target',
                // Retained counts before the split keep the generic wording.
                'redirect_blocked', 'blocked' => 'blocked',
                'too_many_redirects' => 'redirect_limit',
                'invalid_request', 'invalid_url' => 'invalid_url',
                'invalid' => 'invalid_xml',
                'credentials' => 'credentials',
                'url_length' => 'url_length',
                'recursion_limit' => 'recursion_limit',
                'url_limit' => 'url_limit',
                'time_limit' => 'time_limit',
                default => throw new \LogicException('Unknown sitemap reason.'),
            };
            if ($key === 'invalid') {
                $diagnosed = array_filter($snapshot->issues, static fn (array $issue): bool => $issue['type'] === 'sitemap.issue.invalid' && isset($issue['xml_reason']));
                foreach ($diagnosed as $index => $issue) {
                    $part = SitemapXmlProblem::presentation($issue);
                    if ($part instanceof CheckRunPresentationPartData) {
                        $reasons[] = ['key' => 'invalid_'.$index, 'severity' => RunResult::Failed, 'occurrences' => 1, 'part' => $part];
                    }
                }
                $count -= count($diagnosed);
                if ($count === 0) {
                    continue;
                }
            }
            $params = ['count' => $count];
            if ($http) {
                $params['status'] = (int) substr($key, strlen('http_'));
                if ($params['status'] > 599) {
                    $label = 'http_status_nonstandard';
                }
            }
            $subject = match ($label) {
                'invalid_xml', 'recursion_limit', 'url_limit' => 'sitemap.presentation.document_subject',
                'time_limit' => 'sitemap.presentation.check_subject',
                default => 'sitemap.presentation.url_subject',
            };
            $reasons[] = ['key' => $key, 'severity' => $key === 'time_limit' ? RunResult::Errored : RunResult::Failed,
                'occurrences' => $count, 'part' => new CheckRunPresentationPartData('sitemap.presentation.'.$label, $params, ['subject' => $subject])];
        }

        if ($snapshot->not_verifiable !== []) {
            $reasons[] = ['key' => 'not_verifiable', 'severity' => RunResult::Succeeded, 'occurrences' => count($snapshot->not_verifiable),
                'part' => new CheckRunPresentationPartData('sitemap.presentation.not_verifiable', ['count' => count($snapshot->not_verifiable)])];
        }

        if ($reasons === []) {
            return null;
        }
        $presentation = CheckRunPresentationData::forReasons($reasons);
        // Operational errors retain their existing history reason ahead of informational counts.
        if ($snapshot->not_verifiable !== [] && $run->result !== RunResult::Errored) {
            $label = new CheckRunPresentationPartData($snapshot->totalIssuesCount === 0 ? 'sitemap.presentation.not_verifiable' : 'sitemap.history.with_not_verifiable', ['count' => count($snapshot->not_verifiable), 'issues' => $snapshot->totalIssuesCount]);

            return new CheckRunPresentationData($presentation->parts, history_label: $label);
        }

        $first = $presentation->parts[0] ?? null;
        if ($first !== null && str_starts_with($first->key, 'sitemap.xml.')) {
            return new CheckRunPresentationData($presentation->parts, history_label: $first);
        }

        return $presentation;
    }

    public function persistence(): RunPersistence
    {
        return $this->store;
    }

    public function execute(CheckContext $context): CheckOutcome
    {
        $limits = [];
        foreach (['max_urls', 'max_sitemaps', 'max_depth', 'max_xml_bytes'] as $key) {
            $value = Config::get('sitemap.'.$key);
            if (! is_int($value) || $value < 1) {
                throw new \InvalidArgumentException('Sitemap limits must be positive integers.');
            }
            $limits[$key] = $value;
        }
        /** @var array{path?: string, speed?: string, urls_per_run?: string} $config */
        $config = $context->config;
        $delay = match ($config['speed'] ?? 'default') {
            'default' => 25, 'fast' => 10, 'slow' => 50, 'slowest' => 100, 'fastest' => 0,
            default => throw new \InvalidArgumentException('Invalid sitemap speed.'),
        };
        $urlsPerRun = $config['urls_per_run'] ?? '500';
        $probeLimit = match ($urlsPerRun) {
            '0' => 0, '100' => 100, '500' => 500, '1000' => 1000, 'max' => $limits['max_urls'],
            default => throw new \InvalidArgumentException('Invalid sitemap URLs per run.'),
        };
        // The effective probe limit keeps its earlier name and position so retained comparison keys stay stable at the default.
        $limits = ['max_reachability_urls' => $probeLimit, ...$limits];
        $client = $this->http->pacedClient($context, $delay);
        $secrets = array_column($context->crawlerHeaders, 'value');
        $checkUrl = Uri::new($context->url)->resolve($config['path'] ?? '/sitemap.xml')->toString();
        // Only the conventional default may be absent; a configured path that 404s is a real failure.
        $checksDefaultPath = $checkUrl === Uri::new($context->url)->resolve('/sitemap.xml')->toString();
        /** @var list<array{string, int}> $queue */
        $queue = [[$checkUrl, 0]];
        $scheduled = [$checkUrl => true];
        $urlIssues = [];
        $checkedUrls = [];
        $listedUrls = [$this->publicUrl($checkUrl, $secrets) => true];
        $discoveryComplete = true;
        $processedUrls = 0;
        $issues = [];
        $indexes = [];
        $sitemaps = [];
        $exhausted = false;
        $availabilityFailure = null;
        $notVerifiable = [];
        $rateLimitedUrls = [];
        $blockedRootReason = null;
        /** @var array<string, true> $probeable Distinct probeable listed URLs in discovery order. */
        $probeable = [];
        /** @var list<array{url: string, urlCount: int, keys: list<array{string, string}>, allChecked: bool, issues: list<array{url: string, type: string, kind?: string, status?: int|null}>}> $documents */
        $documents = [];
        while ($queue !== []) {
            [$url, $depth] = array_shift($queue);
            if ($this->remainingMilliseconds($context) <= 0) {
                $issues[] = $this->issue($url, 'sitemap.issue.time_limit', $secrets);
                break;
            }
            if ($depth > $limits['max_depth']) {
                $discoveryComplete = false;
                $issues[] = $this->issue($url, 'sitemap.issue.recursion_limit', $secrets);

                continue;
            }
            $checkedUrls[$this->publicUrl($url, $secrets)] = true;
            $localIssues = [];
            if (Uri::new($url)->getUserInfo() !== null) {
                $discoveryComplete = false;
                $localIssues[] = $this->issue($url, 'sitemap.issue.credentials', $secrets);
            } else {
                $response = $this->fetch($client, $context, 'GET', $url, $limits['max_xml_bytes'], $url === $checkUrl);
                if (! $response instanceof FetchResult || $this->remainingMilliseconds($context) <= 0) {
                    $discoveryComplete = false;
                    $exhausted = true;
                    $localIssues[] = $this->issue($url, 'sitemap.issue.time_limit', $secrets);
                } elseif (($block = AutomatedCheckBlock::reason($response->status, $response->headers, substr($response->body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES))) instanceof AutomatedCheckBlockReason) {
                    $notVerifiable[$this->publicUrl($url, $secrets)] = ['url' => $this->publicUrl($url, $secrets), 'reason' => $block->value, 'status' => $response->status];
                    $discoveryComplete = false;
                    if ($url === $checkUrl) {
                        $blockedRootReason = $block;
                    }
                } elseif ($checksDefaultPath && $url === $checkUrl && $response->failure?->kind === FetchFailureKind::HttpStatus && $response->status === 404) {
                    $robotsUrl = Uri::new($context->url)->resolve('/robots.txt')->toString();
                    $robots = $this->fetch($client, $context, 'GET', $robotsUrl, $limits['max_xml_bytes'], true);
                    if (! $robots instanceof FetchResult) {
                        $discoveryComplete = false;
                        $exhausted = true;
                        $localIssues[] = $this->issue($robotsUrl, 'sitemap.issue.time_limit', $secrets);
                    } elseif (($block = AutomatedCheckBlock::reason($robots->status, $robots->headers, substr($robots->body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES))) instanceof AutomatedCheckBlockReason) {
                        $notVerifiable[$this->publicUrl($robotsUrl, $secrets)] = ['url' => $this->publicUrl($robotsUrl, $secrets), 'reason' => $block->value, 'status' => $robots->status];
                        $discoveryComplete = false;
                        $blockedRootReason = $block;
                    } elseif ($robots->failure instanceof FetchFailure && ($robots->failure->kind !== FetchFailureKind::HttpStatus || $robots->status !== 404)) {
                        $discoveryComplete = false;
                        $localIssues[] = $this->failureIssue($robotsUrl, $robots->failure, $secrets);
                    } else {
                        // Reading one directive past the cap shows whether discovery omitted a sitemap.
                        $locations = SitemapLocations::fromRobots($robots->body, $limits['max_sitemaps'] + 1);
                        if (count($locations) > $limits['max_sitemaps']) {
                            $discoveryComplete = false;
                            $locations = array_slice($locations, 0, $limits['max_sitemaps']);
                        }
                        if ($locations === []) {
                            $snapshot = new SitemapSnapshot($this->publicUrl($checkUrl, $secrets), 0, 0, false, [], [], [], notes: ['sitemap.not_published']);
                            /** @var array<string, mixed> $row */
                            $row = $snapshot->toArray();
                            $this->store->stage($context, 'snapshot', [[...$row, 'comparisonIssues' => [], 'unchecked' => [], 'observed_urls' => array_keys($checkedUrls),
                                'comparison_key' => hash('sha256', json_encode([$context->url, $context->config, $context->crawlerHeaders, $limits], JSON_THROW_ON_ERROR)),
                                'findings_coverage' => new FindingsCoverage(true, false, false, false, false, false, true, true, true, false,
                                    ['documents' => 0, 'discovered_urls' => 0, 'reachability_checked' => 0], $limits)->toArray()]]);

                            return new CheckOutcome(RunResult::Succeeded, 'sitemap.not_published', [], new SitemapMetrics(0, 0, false), evidence: $snapshot);
                        }
                        foreach ($locations as $location) {
                            if (filter_var($location, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($location, PHP_URL_SCHEME), ['http', 'https'], true)) {
                                $discoveryComplete = false;
                                $localIssues[] = $this->issue($robotsUrl, 'sitemap.issue.invalid_url', $secrets);

                                continue;
                            }
                            if (! isset($scheduled[$location])) {
                                $scheduled[$location] = true;
                                $listedUrls[$this->publicUrl($location, $secrets)] = true;
                                $queue[] = [$location, 0];
                            } elseif ($location === $checkUrl) {
                                $localIssues[] = $this->failureIssue($url, $response->failure, $secrets);
                            }
                        }
                    }
                } elseif ($response->failure instanceof FetchFailure) {
                    if ($url === $checkUrl && Uri::new($url)->getOrigin() === Uri::new($context->url)->getOrigin()) {
                        $availabilityFailure = AvailabilityFailure::fromFetchFailure($response->failure);
                    }
                    $discoveryComplete = false;
                    $localIssues[] = $this->failureIssue($url, $response->failure, $secrets);
                } else {
                    try {
                        $document = $this->documents->read($response->body, $limits['max_xml_bytes']);
                    } catch (SitemapSizeExceeded) {
                        $localIssues[] = $this->issue($url, 'sitemap.issue.size_limit', $secrets);
                        $document = null;
                    }
                    if (! $document instanceof DOMDocument) {
                        $discoveryComplete = false;
                        if ($localIssues === []) {
                            $localIssues[] = [...$this->issue($url, 'sitemap.issue.invalid', $secrets), ...$document->params($secrets)];
                        }
                    } elseif ($document instanceof DOMDocument) {
                        // DOM serialization includes the declaration so Spatie parses CDATA as XML, not HTML.
                        $xml = $document->saveXML();
                        if ($xml === false) {
                            throw new \RuntimeException('Cannot serialize validated sitemap XML.');
                        }
                        $entries = (new SitemapUrlParser)->extractUrls($xml, $url);
                        if ($entries === []) {
                            $discoveryComplete = false;
                            $localIssues[] = [...$this->issue($url, 'sitemap.issue.invalid', $secrets), ...new SitemapXmlProblem(SitemapXmlReason::NoEntries)->params()];
                        } elseif ($document->documentElement?->localName === 'sitemapindex') {
                            foreach ($entries as $entry) {
                                if (strlen($entry->url) > 2048) {
                                    if ($processedUrls >= $limits['max_urls']) {
                                        $discoveryComplete = false;
                                        $localIssues[] = $this->issue($url, 'sitemap.issue.url_limit', $secrets);
                                        break;
                                    }
                                    $processedUrls++;
                                    $localIssues[] = $this->issue($entry->url, 'sitemap.issue.url_length', $secrets);
                                    $discoveryComplete = false;

                                    continue;
                                }
                                if (isset($scheduled[$entry->url])) {
                                    continue;
                                }
                                // Bound queued documents too; shared or cyclic indexes are visited only once.
                                if (count($scheduled) >= $limits['max_sitemaps']) {
                                    $discoveryComplete = false;
                                    $localIssues[] = $this->issue($url, 'sitemap.issue.recursion_limit', $secrets);
                                    break;
                                }
                                $listedUrls[$this->publicUrl($entry->url, $secrets)] = true;
                                $scheduled[$entry->url] = true;
                                $queue[] = [$entry->url, $depth + 1];
                            }
                            $indexes[] = ['url' => $this->publicUrl($url, $secrets), 'issues' => $localIssues];
                        } else {
                            $allChecked = true;
                            /** @var list<array{string, string}> $keys */
                            $keys = [];
                            // Retain known-but-unchecked entries even when the execution budget ends before probing.
                            foreach (array_slice($entries, 0, max(0, $limits['max_urls'] - $processedUrls)) as $entry) {
                                $listedUrls[$this->publicUrl($entry->url, $secrets)] = true;
                            }
                            foreach ($entries as $entry) {
                                if ($this->remainingMilliseconds($context) <= 0) {
                                    $localIssues[] = $this->issue($url, 'sitemap.issue.time_limit', $secrets);
                                    $allChecked = false;
                                    $exhausted = true;
                                    break;
                                }
                                if ($processedUrls >= $limits['max_urls']) {
                                    $discoveryComplete = false;
                                    $localIssues[] = $this->issue($url, 'sitemap.issue.url_limit', $secrets);
                                    $allChecked = false;
                                    break;
                                }
                                $listedUrls[$this->publicUrl($entry->url, $secrets)] = true;
                                $processedUrls++;
                                $oversized = strlen($entry->url) > 2048;
                                $urlKey = $oversized ? $this->publicUrl($entry->url, $secrets) : $entry->url;
                                $keys[] = [$urlKey, $this->publicUrl($entry->url, $secrets)];
                                if (! array_key_exists($urlKey, $urlIssues)) {
                                    // Oversized and credential-bearing locations are structure issues; they never take a probe slot.
                                    if ($oversized) {
                                        $urlIssues[$urlKey] = [$this->issue($entry->url, 'sitemap.issue.url_length', $secrets)];
                                    } elseif (Uri::new($entry->url)->getUserInfo() !== null) {
                                        $urlIssues[$urlKey] = [$this->issue($entry->url, 'sitemap.issue.credentials', $secrets)];
                                    } else {
                                        $urlIssues[$urlKey] = null;
                                        $probeable[$urlKey] = true;
                                    }
                                }
                            }
                            $documents[] = ['url' => $url, 'urlCount' => count($entries), 'keys' => $keys, 'allChecked' => $allChecked, 'issues' => $localIssues];
                        }
                    }
                }
            }
            array_push($issues, ...$localIssues);
            if ($exhausted) {
                break;
            }
        }
        // Rotation: each run probes the next window of the probeable list, continuing where the last accepted run stopped.
        $probeOrder = array_keys($probeable);
        $rotationStart = $this->store->rotationStart($context->checkId);
        if ($rotationStart >= count($probeOrder)) {
            $rotationStart = 0;
        }
        $probed = 0;
        $unanswered = [];
        if (! $exhausted) {
            $window = array_slice($probeOrder, $rotationStart, $urlsPerRun === 'max' ? null : $limits['max_reachability_urls']);
            // As many as fit: paced batches of 100 continue only while twice the last batch's duration plus a reserve remains.
            $batchMilliseconds = 100 * (110 + $delay);
            foreach ($urlsPerRun === 'max' ? array_chunk($window, 100) : [$window] as $batch) {
                if ($urlsPerRun === 'max' && $this->remainingMilliseconds($context) < 2 * $batchMilliseconds + 10000) {
                    break;
                }
                $batchStarted = CarbonImmutable::now();
                $pending = [];
                foreach ($batch as $target) {
                    $pending[$target] = $this->request($context, 'HEAD', $target, $limits['max_xml_bytes']);
                }
                $results = $this->fetchBatches($client, $pending);
                $fallback = [];
                foreach ($results as $target => $result) {
                    if ($result?->failure?->kind === FetchFailureKind::HttpStatus) {
                        $fallback[$target] = $this->request($context, 'GET', $target, $limits['max_xml_bytes']);
                    }
                }
                $results = array_replace($results, $this->fetchBatches($client, $fallback));
                foreach ($results as $target => $result) {
                    if ($result === null) {
                        $exhausted = true;
                        $unanswered[$target] = true;
                    } else {
                        $block = AutomatedCheckBlock::reason($result->status, $result->headers, substr($result->body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES));
                        if ($block instanceof AutomatedCheckBlockReason) {
                            $notVerifiable[$this->publicUrl($target, $secrets)] = ['url' => $this->publicUrl($target, $secrets), 'reason' => $block->value, 'status' => $result->status];
                        }
                        if ($block === AutomatedCheckBlockReason::RateLimited) {
                            // A rate limit supplies no recovery evidence for an earlier broken URL.
                            $rateLimitedUrls[$this->publicUrl($target, $secrets)] = true;
                            $discoveryComplete = false;
                            $urlIssues[$target] = null;
                        } else {
                            $urlIssues[$target] = $this->reachabilityIssues($target, $result, $secrets);
                        }
                    }
                }
                $probed += count($batch);
                $batchMilliseconds = (int) $batchStarted->diffInMilliseconds(CarbonImmutable::now());
                if ($exhausted) {
                    break;
                }
            }
        }
        $reachabilityCapReached = $probed < count($probeOrder);
        $rotationNext = $rotationStart + $probed >= count($probeOrder) ? 0 : $rotationStart + $probed;
        foreach ($documents as $document) {
            $documentIssues = $document['issues'];
            $allChecked = $document['allChecked'];
            $windowTimedOut = false;
            foreach ($document['keys'] as [$urlKey, $publicEntryUrl]) {
                if ($urlIssues[$urlKey] === null) {
                    $allChecked = false;
                    $windowTimedOut = $windowTimedOut || isset($unanswered[$urlKey]);
                } else {
                    // A bot-protected URL was not verified; it cannot clear an earlier finding.
                    if (! isset($notVerifiable[$publicEntryUrl])) {
                        $checkedUrls[$publicEntryUrl] = true;
                    }
                    array_push($documentIssues, ...$urlIssues[$urlKey]);
                    array_push($issues, ...$urlIssues[$urlKey]);
                }
            }
            if ($windowTimedOut) {
                $documentIssues[] = $issues[] = $this->issue($document['url'], 'sitemap.issue.time_limit', $secrets);
            }
            $sitemaps[] = ['url' => $this->publicUrl($document['url'], $secrets), 'urlCount' => $document['urlCount'], 'checkedReachabilityOfAllUrls' => $allChecked, 'issuesCount' => count($documentIssues), 'issues' => $documentIssues];
        }
        if ($this->remainingMilliseconds($context) <= 0 && ! in_array('sitemap.issue.time_limit', array_column($issues, 'type'), true)) {
            $discoveryComplete = false;
            $issues[] = $this->issue($checkUrl, 'sitemap.issue.time_limit', $secrets);
        }
        $issues = $this->canonicalIssues($issues);
        $baseline = $this->store->baseline($context->checkId);
        if ($baseline !== null) {
            foreach ($baseline['issues'] as &$issue) {
                $issue['url'] = SecretRedactor::redact($issue['url'], $secrets);
            }
            unset($issue);
        }
        $issueTypes = array_column($issues, 'type');
        $timedOut = in_array('sitemap.issue.time_limit', $issueTypes, true);
        $limited = in_array(false, array_column($sitemaps, 'checkedReachabilityOfAllUrls'), true)
            || array_intersect($issueTypes, ['sitemap.issue.url_limit', 'sitemap.issue.recursion_limit', 'sitemap.issue.size_limit']) !== [];
        if ($baseline !== null && $discoveryComplete && ! $timedOut) {
            $baseline['issues'] = array_values(array_filter($baseline['issues'], static fn (array $issue): bool => isset($listedUrls[$issue['url']])));
        }
        $comparisonIssues = $issues;
        $observedFailure = $issues !== [];
        if (($limited || ! $discoveryComplete || $notVerifiable !== []) && $baseline !== null && ! $timedOut) {
            // A configured cap is comparable, but cannot clear findings on unobserved URLs.
            foreach ($baseline['issues'] as $issue) {
                if (! isset($checkedUrls[$issue['url']]) && ($limited || ! $discoveryComplete || isset($notVerifiable[$issue['url']]))) {
                    $comparisonIssues[] = $issue;
                    if (isset($rateLimitedUrls[$issue['url']]) || isset($notVerifiable[$issue['url']])) {
                        $issues[] = $issue;
                    }
                }
            }
        }
        $issues = $this->canonicalIssues($issues);
        $comparisonIssues = $this->canonicalIssues($comparisonIssues, diagnostics: false);
        $overflow = count($comparisonIssues) > $limits['max_urls'] + $limits['max_sitemaps'];
        $comparable = ! $timedOut && ! $overflow;
        $snapshot = new SitemapSnapshot($this->publicUrl($checkUrl, $secrets), count($issues), count($urlIssues), $issues !== [], $issues, $indexes, $sitemaps,
            notes: $overflow ? ['sitemap.note.comparison_limit'] : [], not_verifiable: array_values($notVerifiable));
        /** @var array<string, mixed> $row */
        $row = $snapshot->toArray();
        $unchecked = array_keys(array_diff_key($listedUrls, $checkedUrls, $notVerifiable));
        $coverage = new FindingsCoverage($discoveryComplete && ! $timedOut, $limited || $timedOut || ! $discoveryComplete,
            in_array('sitemap.issue.recursion_limit', $issueTypes, true), $reachabilityCapReached || in_array('sitemap.issue.url_limit', $issueTypes, true),
            $timedOut, $overflow, true, true, true, false,
            ['documents' => count($sitemaps) + count($indexes), 'discovered_urls' => count($listedUrls),
                'reachability_checked' => count(array_filter($urlIssues, static fn (?array $findings): bool => $findings !== null)),
                'not_verifiable' => count($notVerifiable), 'unchecked' => count($unchecked)], $limits);
        $this->store->stage($context, 'snapshot', [[...$row, 'comparisonIssues' => $comparable ? $comparisonIssues : null, 'rotation_next' => $rotationNext,
            'unchecked' => $unchecked, 'observed_urls' => array_keys($checkedUrls), 'findings_coverage' => $coverage->toArray(),
            'comparison_key' => hash('sha256', json_encode([$context->url, $context->config, $context->crawlerHeaders, $limits], JSON_THROW_ON_ERROR))]]);
        $events = [];
        if ($comparable && $baseline !== null && $this->canonicalIssues($baseline['issues']) !== $comparisonIssues) {
            $new = array_column(array_map(static fn (array $issue): array => [...$issue, 'identity' => json_encode($issue, JSON_THROW_ON_ERROR)], $comparisonIssues), null, 'identity');
            $old = array_column(array_map(static fn (array $issue): array => [...$issue, 'identity' => json_encode($issue, JSON_THROW_ON_ERROR)], $this->canonicalIssues($baseline['issues'])), null, 'identity');
            $withoutIdentity = static function (array $issue): array {
                unset($issue['identity']);

                return $issue;
            };
            $events[] = new AddonEvent('sitemap.issues_changed', 'sitemap:'.$context->checkId.':'.$baseline['run_id'].':'.hash('sha256', json_encode($comparisonIssues, JSON_THROW_ON_ERROR)), 1, [
                'new' => array_values(array_map($withoutIdentity, array_diff_key($new, $old))),
                'resolved' => array_values(array_map($withoutIdentity, array_filter(array_diff_key($old, $new), static fn (array $issue): bool => ($notVerifiable[$issue['url']]['reason'] ?? null) !== AutomatedCheckBlockReason::BotProtection->value))),
            ]);
        }

        if ($timedOut) {
            $checked = count(array_filter($urlIssues, static fn (?array $findings): bool => $findings !== null));

            return new CheckOutcome(RunResult::Errored, 'sitemap.incomplete_time_limit_progress', ['checked' => $checked, 'total' => count($urlIssues)], null, evidence: $snapshot->bounded(),
                error: new CheckError(ExternalTimeoutException::class, 'Sitemap execution time limit reached.'), incomplete: true, verifiedNothing: $sitemaps === [] && $indexes === []);
        }

        $hasFailures = $comparisonIssues !== [];
        $result = $hasFailures ? RunResult::Failed : RunResult::Succeeded;
        [$summary, $summaryParams] = $hasFailures
            ? ($issues === [] ? ['sitemap.summary.retained_issues', []] : SitemapIssueSummary::forSnapshot($snapshot, array_map(fn (string $url): string => $this->publicUrl($url, $secrets), array_keys($scheduled))))
            : ['sitemap.succeeded', []];

        $botProtectionCount = count(array_filter($notVerifiable, static fn (array $finding): bool => $finding['reason'] === AutomatedCheckBlockReason::BotProtection->value));
        if ($result === RunResult::Succeeded && $botProtectionCount > 0 && ! $blockedRootReason instanceof AutomatedCheckBlockReason && $rateLimitedUrls === []) {
            $summary = 'sitemap.no_verified_issues_'.($botProtectionCount === 1 ? 'one' : 'other');
            $summaryParams = ['count' => $botProtectionCount, 'presentation' => 'direct'];
        }

        $verifiedNothing = $blockedRootReason instanceof AutomatedCheckBlockReason && $sitemaps === [] && $indexes === [];
        $incomplete = $blockedRootReason instanceof AutomatedCheckBlockReason || $rateLimitedUrls !== [];
        if ($verifiedNothing) {
            $summary = 'sitemap.not_checked.'.$blockedRootReason->value;
            $summaryParams = [];
        }

        return new CheckOutcome($result, $summary, $summaryParams, new SitemapMetrics(count($issues), count($urlIssues), $issues !== []), evidence: $snapshot->bounded(), events: $events, incomplete: $incomplete, confirmedIncompleteFailure: $incomplete && $observedFailure, availabilityFailure: $availabilityFailure, verifiedNothing: $verifiedNothing);
    }

    /**
     * Dispatch and pacing advance the clock between budget checks.
     *
     * @phpstan-impure
     */
    private function remainingMilliseconds(CheckContext $context): int
    {
        return (int) CarbonImmutable::now()->diffInMilliseconds($context->deadline, absolute: false);
    }

    private function fetch(PacedHttpClient $client, CheckContext $context, string $method, string $url, int $maxBytes, bool $operator = false): ?FetchResult
    {
        $result = $client->fetch($this->request($context, $method, $url, $maxBytes, $operator));
        if ($result?->failure?->kind === FetchFailureKind::InvalidRequest && ($operator || $result->failure->headerName !== null || $result->failure->headerPosition !== null)) {
            throw new FetchException($result->failure);
        }

        return $result instanceof FetchResult ? $this->classifyResponse($result) : null;
    }

    /**
     * The paced client bounds one batch to 500 requests; larger per-run settings dispatch consecutive batches.
     *
     * @param  array<string, FetchRequest>  $requests
     * @return array<string, FetchResult|null>
     */
    private function fetchBatches(PacedHttpClient $client, array $requests): array
    {
        $results = [];
        foreach (array_chunk($requests, 500, true) as $batch) {
            foreach ($client->fetchMany($batch) as $target => $response) {
                $results[(string) $target] = $response instanceof FetchResult ? $this->classifyResponse($response) : null;
            }
        }

        return $results;
    }

    /** Preserve explicit block evidence; ordinary rejected statuses keep the existing failure path. */
    private function classifyResponse(FetchResult $response): FetchResult
    {
        if ($response->failure instanceof FetchFailure || $response->status === 200 || AutomatedCheckBlock::reason($response->status, $response->headers, substr($response->body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES)) instanceof AutomatedCheckBlockReason) {
            return $response;
        }

        return new FetchResult($response->status, failure: new FetchFailure(FetchFailureKind::HttpStatus, $response->status));
    }

    private function request(CheckContext $context, string $method, string $url, int $maxBytes, bool $operator = false): FetchRequest
    {
        $site = Uri::new($context->url);
        $siteHost = TargetHost::normalize($site->getHost() ?? throw new \InvalidArgumentException('Sitemap site URL needs a host.'));
        $target = Uri::new($url);
        $targetHost = TargetHost::normalize($target->getHost() ?? throw new \InvalidArgumentException('Sitemap target URL needs a host.'));
        $headers = [];
        if ($target->withHost($targetHost)->isSameOrigin($site->withHost($siteHost))) {
            foreach ($context->crawlerHeaders as $header) {
                $headers[strtolower($header['name'])][] = $header['value'];
            }
        }

        return new FetchRequest($url, $method, $headers, maxWireBytes: $maxBytes, maxDecodedBytes: $maxBytes,
            redirects: $operator ? RedirectPolicy::Operator : RedirectPolicy::Untrusted, siteHost: $siteHost, timeoutSeconds: 10.0, acceptedStatuses: range(200, 599),
            // A listed URL or child sitemap that the guard refuses is a per-item issue; the configured sitemap URL still errors the run.
            refusalIsFinding: ! $operator);
    }

    /**
     * @param  list<string>  $secrets
     * @return list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>
     */
    private function reachabilityIssues(string $url, FetchResult $response, array $secrets): array
    {
        if ($response->failure?->kind === FetchFailureKind::InvalidRequest && ($response->failure->headerName !== null || $response->failure->headerPosition !== null)) {
            throw new FetchException($response->failure);
        }

        return ! $response->failure instanceof FetchFailure || ($response->failure->kind === FetchFailureKind::TooLarge && $response->failure->status === 200)
            ? [] : [$this->failureIssue($url, $response->failure, $secrets)];
    }

    /** @param list<string> $secrets
     * @return array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}
     */
    private function failureIssue(string $url, FetchFailure $failure, array $secrets): array
    {
        $type = match ($failure->kind) {
            FetchFailureKind::TooLarge => 'sitemap.issue.size_limit',
            FetchFailureKind::PrivateTarget, FetchFailureKind::RedirectBlocked => 'sitemap.issue.blocked',
            FetchFailureKind::InvalidRequest => 'sitemap.issue.invalid_url',
            default => 'sitemap.issue.unreachable',
        };

        return [...$this->issue($url, $type, $secrets), 'kind' => $failure->kind->value, 'status' => $failure->status];
    }

    /** @param list<string> $secrets
     * @return array{url: string, type: string}
     */
    private function issue(string $url, string $type, array $secrets): array
    {
        return ['url' => $this->publicUrl($url, $secrets), 'type' => $type];
    }

    /** @param list<string> $secrets */
    private function publicUrl(string $url, array $secrets): string
    {
        $public = SecretRedactor::redact(Uri::new($url)->withUserInfo(null)->withFragment(null)->toString(), $secrets);

        return strlen($public) <= 2048 ? $public : mb_strcut($public, 0, 2048, 'UTF-8').'…#sha256='.hash('sha256', $public);
    }

    /** @param list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}> $issues
     * @return list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>
     */
    private function canonicalIssues(array $issues, bool $diagnostics = true): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            ksort($issue);
            $identity = SitemapIssueCounts::identity($issue);
            ksort($identity);
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            $unique[$key] ??= $diagnostics ? $issue : $identity;
        }
        ksort($unique);

        return array_values($unique);
    }
}
