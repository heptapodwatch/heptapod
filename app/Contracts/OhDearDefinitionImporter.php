<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface OhDearDefinitionImporter
{
    /** @param list<array<string, mixed>> $definitions */
    public function validate(array $definitions): void;

    /** @param list<array<string, mixed>> $definitions */
    public function __invoke(int $checkId, array $definitions): void;
}
