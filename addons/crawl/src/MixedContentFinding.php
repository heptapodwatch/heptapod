<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\SecretRedactor;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MixedContentFinding extends Data
{
    public function __construct(public string $element_name, public string $mixed_content_url, public string $found_on_url, #[Hidden, TypeScriptHidden] public string $source_url, #[Hidden, TypeScriptHidden] public ?bool $carried_forward = null) {}

    /** @param list<string> $secrets
     * @param  list<string>  $urls
     */
    public function redact(array $secrets, array $urls): void
    {
        $this->source_url = SecretRedactor::redact($this->source_url, $secrets, $urls);
        $this->mixed_content_url = SecretRedactor::redact($this->mixed_content_url, $secrets, $urls);
        $this->found_on_url = SecretRedactor::redact($this->found_on_url, $secrets, $urls);
    }

    public function identity(): string
    {
        return hash('sha256', $this->element_name.'|'.$this->mixed_content_url.'|'.$this->found_on_url);
    }
}
