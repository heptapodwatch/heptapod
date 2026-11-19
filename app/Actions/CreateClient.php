<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Client;
use Illuminate\Support\Str;

final readonly class CreateClient
{
    public function __construct(private UpdateClient $update) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Client
    {
        return ($this->update)(new Client(['slug' => 'client-'.Str::uuid()]), $attributes);
    }
}
