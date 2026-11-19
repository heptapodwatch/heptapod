<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Str;

final readonly class CreateClient
{
    public function __construct(private UpdateClient $update) {}

    /**
     * A client without a chosen zone starts in its creator's zone, or the instance zone without a creator.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, ?User $creator): Client
    {
        $attributes += ['timezone' => $creator->timezone ?? app(InstanceSettings::class)->timezone];

        return ($this->update)(new Client(['slug' => 'client-'.Str::uuid()]), $attributes);
    }
}
