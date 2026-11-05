<?php

declare(strict_types=1);

namespace Heptapod\Casts;

use Heptapod\Contracts\Schema\ValidatedConfig;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<array<string, mixed>, ValidatedConfig> */
final class DestinationSettings implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value)) {
            throw new \UnexpectedValueException('Persisted destination config must be JSON.');
        }
        $config = json_decode($value, flags: JSON_THROW_ON_ERROR);
        if (! $config instanceof \stdClass) {
            throw new \UnexpectedValueException('Persisted destination config must be an object.');
        }

        /** @var array<string, mixed> $public */
        $public = (array) $config;

        return $public;
    }

    /** @return array<string, mixed> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof ValidatedConfig) {
            throw new \InvalidArgumentException('Destination config must be validated by the channel schema.');
        }
        $model->setAttribute('config_secrets', $value->secrets());

        return [
            $key => json_encode((object) $value->public, JSON_THROW_ON_ERROR),
            'config_secrets' => $model->getAttributes()['config_secrets'],
        ];
    }
}
