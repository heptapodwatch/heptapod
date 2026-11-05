<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientAvatarSiteData extends Data
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly ?string $host = null) {}
}
