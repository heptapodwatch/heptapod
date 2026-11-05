<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\SecretRedactor;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlArtifact extends Data
{
    /** @param array<string, mixed> $settings
     * @param  list<CrawlFinding>  $urls
     * @param  list<MixedContentFinding>  $mixed_content
     * @param  list<string>  $observed_urls
     * @param  list<string>  $observed_pages
     * @param  list<string>  $discovered_urls
     * @param  list<string>  $nofollow_pages  Collection-only exclusions from broken-links page counts.
     * @param  list<CheckRunPresentationPartData>  $notes
     */
    public function __construct(public string $site_url, public string $collected_at, public array $settings, public array $urls, public array $mixed_content, #[Hidden, TypeScriptHidden] public string $target_fingerprint, public bool $truncated, public array $observed_urls, public array $observed_pages, public array $discovered_urls, public bool $discovery_complete, public bool $page_cap_reached = false, public bool $time_limit_reached = false, public bool $resource_cap_reached = false, #[Hidden, TypeScriptHidden] public ?string $config_fingerprint = null, public ?NotCrawlableReason $not_crawlable_reason = null, #[Hidden, TypeScriptHidden] public array $nofollow_pages = [], public array $notes = [], #[Hidden, TypeScriptHidden] public ?int $classification_policy_version = null, public ?string $start_page_final_url = null, public bool $start_page_too_large = false) {}

    /** Collections and snapshots without a version were labeled before LinkClassificationPolicy::VERSION 1. */
    public function underCurrentPolicy(): self
    {
        if ($this->classification_policy_version === LinkClassificationPolicy::VERSION) {
            return $this;
        }
        if ($this->classification_policy_version !== null) {
            throw new \LogicException('No reclassification from crawl policy version '.$this->classification_policy_version.'.');
        }
        foreach ($this->urls as $finding) {
            $finding->error_message = LinkClassificationPolicy::legacyLabel($finding->status_code, $finding->error_message, $finding->internal);
        }
        $this->classification_policy_version = LinkClassificationPolicy::VERSION;

        return $this;
    }

    /** @return array<mixed> */
    public function privateStorage(): array
    {
        return [...$this->toArray(), 'target_fingerprint' => $this->target_fingerprint, 'config_fingerprint' => $this->config_fingerprint, 'nofollow_pages' => $this->nofollow_pages, 'classification_policy_version' => $this->classification_policy_version, 'urls' => array_map(static fn (CrawlFinding $finding): array => [...$finding->toArray(), 'carried_forward' => $finding->carried_forward], $this->urls), 'mixed_content' => array_map(static fn (MixedContentFinding $finding): array => [...$finding->toArray(), 'source_url' => $finding->source_url, 'carried_forward' => $finding->carried_forward], $this->mixed_content)];
    }

    /** @param list<string> $secrets
     * @param  list<string>  $operatorUrls
     */
    public function redact(#[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $operatorUrls = []): self
    {
        $urls = [$this->site_url, ...$operatorUrls];
        $this->site_url = SecretRedactor::redact($this->site_url, $secrets, $urls);
        if ($this->start_page_final_url !== null) {
            $this->start_page_final_url = SecretRedactor::redact($this->start_page_final_url, $secrets, $urls);
        }
        array_walk_recursive($this->settings, static function (mixed &$value) use ($secrets, $urls): void {
            if (is_string($value)) {
                $value = SecretRedactor::redact($value, $secrets, $urls);
            }
        });
        $this->discovered_urls = array_map(static fn (string $url): string => SecretRedactor::redact($url, $secrets, $urls), $this->discovered_urls);
        $this->observed_urls = array_map(static fn (string $url): string => SecretRedactor::redact($url, $secrets, $urls), $this->observed_urls);
        $this->observed_pages = array_map(static fn (string $url): string => SecretRedactor::redact($url, $secrets, $urls), $this->observed_pages);
        $this->nofollow_pages = array_map(static fn (string $url): string => SecretRedactor::redact($url, $secrets, $urls), $this->nofollow_pages);
        foreach ($this->urls as $finding) {
            $finding->redact($secrets, $urls);
        }
        foreach ($this->mixed_content as $finding) {
            $finding->redact($secrets, $urls);
        }

        return $this;
    }
}
