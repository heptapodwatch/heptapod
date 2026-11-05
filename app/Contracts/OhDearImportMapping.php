<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Addon-owned list of supported source settings and optional definition importer. */
final readonly class OhDearImportMapping
{
    /** @param list<string> $settings
     * @param  class-string<OhDearDefinitionImporter>|null  $definitions
     */
    public function __construct(public array $settings, public ?string $definitions = null) {}
}
