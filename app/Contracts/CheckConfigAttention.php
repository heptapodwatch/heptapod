<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Addon-owned attention notice for a stored configuration that still runs but should be updated. */
interface CheckConfigAttention
{
    /** @param array<string, mixed> $config */
    public function configAttentionReason(#[\SensitiveParameter] array $config): ?string;
}
