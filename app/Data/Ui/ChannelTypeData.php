<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Schema\Schema;
use Illuminate\Support\Facades\Lang;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ChannelTypeData extends Data
{
    /** @return list<self> */
    public static function installed(ChannelRegistry $registry): array
    {
        return array_values(array_map(static function (ChannelType $channel): self {
            $descriptionKey = $channel->descriptionKey();
            foreach (['en', 'de'] as $locale) {
                if (! Lang::hasForLocale($descriptionKey, $locale)) {
                    throw new \LogicException('Channel '.$channel->key().' description translation missing: '.$descriptionKey.' ('.$locale.').');
                }
            }

            return new self($channel->key(), $channel->nameKey(), $descriptionKey, $channel->configSchema());
        }, $registry->all()));
    }

    public function __construct(
        public readonly string $key,
        public readonly string $label_key,
        public readonly string $description_key,
        public readonly Schema $config_schema,
    ) {}
}
