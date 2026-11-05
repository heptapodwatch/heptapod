<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\SecretRedactor;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlFinding extends Data
{
    public function __construct(
        public string $crawled_url,
        public string $relative_crawled_url,
        public ?int $status_code,
        public ?string $found_on_url,
        public ?string $relative_found_on_url,
        public ?string $link_text,
        public string $type,
        public ?string $error_message,
        public bool $internal,
        #[Hidden, TypeScriptHidden] public ?bool $carried_forward = null,
        /** Set only on a retained confirmed failure: the label of the current, unconfirmed observation of the same link. */
        public ?string $current_error_message = null,
    ) {}

    /** @param list<string> $secrets
     * @param  list<string>  $urls
     */
    public function redact(array $secrets, array $urls): void
    {
        $this->crawled_url = SecretRedactor::redact($this->crawled_url, $secrets, $urls);
        $this->relative_crawled_url = SecretRedactor::redact($this->relative_crawled_url, $secrets, $urls);
        $this->found_on_url = $this->found_on_url === null ? null : SecretRedactor::redact($this->found_on_url, $secrets, $urls);
        $this->relative_found_on_url = $this->relative_found_on_url === null ? null : SecretRedactor::redact($this->relative_found_on_url, $secrets, $urls);
        $this->link_text = $this->link_text === null ? null : SecretRedactor::redact($this->link_text, $secrets, $urls);
    }

    public function broken(): bool
    {
        return LinkClassificationPolicy::broken($this->status_code, $this->error_message, $this->internal);
    }
}
