<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlEvidence extends Data
{
    /** @param list<string> $finding_ids
     * @param  list<CheckRunPresentationPartData>  $notes
     */
    public function __construct(public array $finding_ids, public bool $truncated, public int $unchecked_count, public bool $discovery_complete, public bool $crawl_truncated, public int $page_cap, public int $observed_pages, public int $observed_urls, public int $discovered_urls, public bool $page_cap_reached = false, public bool $time_limit_reached = false, public bool $resource_cap_reached = false, public bool $comparison_limit_reached = false, public array $notes = [],
        /** Runs recorded before bot-protection classification have no retained count. */
        public int $not_verifiable_count = 0,
        /** Part of not_verifiable_count: external transport doubts (DNS, untrusted TLS); runs before LinkClassificationPolicy v1 recorded none. */
        public int $external_not_verifiable_count = 0,
        /** The shared result label of those links, or "mixed". */
        public ?string $external_not_verifiable_reason = null,
        /** Part of not_verifiable_count: external links whose site refused the automated check. */
        public int $external_refused_count = 0,
        public ?string $external_refused_reason = null,
        /** @var array<string, int> Not-verifiable result label => link count; empty on runs recorded before per-reason counts. */
        public array $not_verifiable_reasons = []) {}

    /** Keep collection provenance separate from problem and change headlines. */
    public static function presentCollection(CheckRunPresentationInput $run, ?CheckRunPresentationData $presentation): ?CheckRunPresentationData
    {
        $observation = $run->observationEvidence();
        if ($observation === []) {
            return $presentation;
        }
        $evidence = self::hydrateObservation($observation);
        $details = array_values(array_filter($evidence->notes, static fn (CheckRunPresentationPartData $note): bool => $note->key === 'crawl.collection_reused'));
        if ($details === []) {
            return $presentation;
        }
        $presentation ??= new CheckRunPresentationData([new CheckRunPresentationPartData($run->summary, $run->summaryParams)]);

        return new CheckRunPresentationData($presentation->parts, $presentation->explanation, $presentation->history_label,
            $presentation->names_check, $presentation->headline_limit, $presentation->changes, [...$presentation->details, ...$details]);
    }

    /** @param array<string, mixed> $evidence */
    public static function hydrateObservation(array $evidence): self
    {
        // Retained runs before structured coverage notes stored bare translation keys.
        $notes = $evidence['notes'] ?? [];
        if (! is_array($notes) || ! array_is_list($notes)) {
            throw new \LogicException('Crawl notes must be a list.');
        }
        $evidence['notes'] = array_map(static fn (mixed $note): mixed => is_string($note) ? ['key' => $note, 'params' => [], 'labels' => []] : $note, $notes);

        return self::from($evidence);
    }
}
