<?php

namespace Heptapod\Casts;

use Heptapod\Contracts\Schema\ValidatedConfig;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<array<string, mixed>, ValidatedConfig> */
final class CheckConfig implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value)) {
            throw new \UnexpectedValueException('Persisted check config must be JSON.');
        }
        $config = json_decode($value, flags: JSON_THROW_ON_ERROR);
        if (! $config instanceof \stdClass) {
            throw new \UnexpectedValueException('Persisted check config must be an object.');
        }

        /** @var array<string, mixed> $public */
        $public = (array) $config;

        return $public;
    }

    /** @return array<string, mixed> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof ValidatedConfig) {
            throw new \InvalidArgumentException('Check config must be validated by the addon schema.');
        }
        if (array_intersect(['frequency_value', 'frequency_unit', 'failed_notification_threshold'], array_keys($value->public)) !== []) {
            throw new \InvalidArgumentException('Scheduling and failure threshold belong in canonical check columns.');
        }
        $model->setAttribute('config_secrets', $value->secrets());

        return [
            $key => json_encode((object) $value->public, JSON_THROW_ON_ERROR),
            'config_secrets' => $model->getAttributes()['config_secrets'],
        ];
    }
}
