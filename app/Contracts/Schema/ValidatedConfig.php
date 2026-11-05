<?php

namespace Heptapod\Contracts\Schema;

/** Not a generic DTO: decrypted secrets are intentionally excluded from debug/JSON output. */
final readonly class ValidatedConfig implements \JsonSerializable
{
    /** @param array<string, mixed> $public
     * @param  array<string, mixed>  $secrets
     */
    public function __construct(public array $public, #[\SensitiveParameter] private array $secrets) {}

    /** Execution and encrypted persistence only; never pass to props/loggers.
     * @return array<string, mixed>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->public;
    }

    public function __debugInfo(): array
    {
        return $this->public;
    }
}
