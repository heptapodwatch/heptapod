<?php

declare(strict_types=1);

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Data\Ui\ChannelTypeData;
use Illuminate\Container\Container;

it('projects channel options from installed registry schemas without a core addon list', function (): void {
    $installed = app(ChannelRegistry::class);
    $container = new Container;
    foreach ($installed->all() as $key => $channel) {
        if ($key === 'slack') {
            continue;
        }
        $container->instance($channel::class, $channel);
        $container->tag($channel::class, ChannelType::class);
    }

    $data = ChannelTypeData::installed(new ChannelRegistry($container));
    $byKey = array_column(array_map(static fn (ChannelTypeData $type): array => $type->toArray(), $data), null, 'key');
    expect($byKey)->not->toHaveKey('slack')->toHaveKey('webhook')
        ->and($byKey['webhook']['label_key'])->toBe('notifications.channel.webhook')
        ->and($byKey['webhook']['config_schema']['fields'])->toHaveKeys(['url', 'secret']);
});
