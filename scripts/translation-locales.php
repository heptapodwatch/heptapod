<?php

declare(strict_types=1);

/** @return list<string> */
function translationLocales(string $root): array
{
    $directories = glob($root.'/lang/*', GLOB_ONLYDIR);
    if ($directories === false) {
        throw new RuntimeException('Could not discover translation catalogs.');
    }

    return array_values(array_diff(array_map(basename(...), $directories), ['vendor']));
}
