<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Addon-owned, secret-free detail snapshots; core never imports their implementations. */
#[TypeScript]
abstract class CheckDetailData extends Data
{
    public function __construct(public readonly CheckKey $check_type) {}

    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  array<array-key, mixed>  $urls
     */
    public function redacted(#[\SensitiveParameter] array $secrets, array $urls = []): static
    {
        $values = $this->toArray();
        foreach ($values as $field => $value) {
            // Keep the registered discriminator and DTO field names intact; only observation data is untrusted.
            if ($field !== 'check_type') {
                $values[$field] = SecretRedactor::redactValues($value, $secrets, $urls);
            }
        }

        return static::from($values);
    }
}
