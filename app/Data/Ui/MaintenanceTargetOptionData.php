<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaintenanceTargetOptionData extends Data
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly ?ClientAvatarData $avatar = null, public readonly ?string $short_name = null, public readonly ?string $accent_color = null, public readonly bool $is_internal = false) {}
}
