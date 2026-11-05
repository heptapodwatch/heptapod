<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Heptapod\Contracts\Schema\ValidatedConfig;

/** Authorized execution only. Generic JSON/debug output contains public config, never secrets. */
final readonly class DestinationConfig implements \JsonSerializable
{
    public function __construct(
        public int $destinationId,
        public string $locale,
        #[\SensitiveParameter] private ValidatedConfig $config,
        public string $timezone,
        public string $instanceName,
        public string $instanceUrl,
    ) {
        if (! in_array($locale, ['en', 'de'], true)) {
            throw new \InvalidArgumentException('Unsupported destination locale.');
        }
    }

    public function withLocale(string $locale): self
    {
        return new self($this->destinationId, $locale, $this->config, $this->timezone, $this->instanceName, $this->instanceUrl);
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        /** @var array<string, mixed> $public */
        $public = json_decode(json_encode($this->config->public, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return array_replace_recursive($public, $this->config->secrets());
    }

    public function string(string $key): string
    {
        $value = $this->values()[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Missing or invalid destination field: '.$key);
        }

        return $value;
    }

    /** @return array{destinationId: int, locale: string, config: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return ['destinationId' => $this->destinationId, 'locale' => $this->locale, 'config' => $this->config->public];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** Prevent PHP serialization from leaking decrypted credentials into queues or logs. */
    public function __serialize(): array
    {
        throw new \LogicException('DestinationConfig is execution-only; queue destination IDs instead.');
    }
}
