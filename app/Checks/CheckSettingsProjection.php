<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Models\Check;
use Illuminate\Support\Arr;

final class CheckSettingsProjection
{
    /** @return array<string, mixed> */
    public static function masked(Check $check): array
    {
        // Persisted config contains nested objects; Arr::set requires array intermediates.
        /** @var array<string, mixed> $settings */
        $settings = json_decode(json_encode($check->config, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        foreach (Arr::dot($check->config_secrets ?? []) as $path => $secret) {
            Arr::set($settings, $path, '********');
        }

        /** @var array<string, mixed> $settings */
        return $settings;
    }
}
