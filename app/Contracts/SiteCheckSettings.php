<?php

namespace Heptapod\Contracts;

final readonly class SiteCheckSettings implements \JsonSerializable
{
    /** @param array<string, mixed> $config Execution-only decrypted settings. */
    public function __construct(public bool $enabled, #[\SensitiveParameter] public array $config) {}

    /** @return array{enabled: bool} */
    public function jsonSerialize(): array
    {
        return ['enabled' => $this->enabled];
    }

    /** @return array{enabled: bool} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
