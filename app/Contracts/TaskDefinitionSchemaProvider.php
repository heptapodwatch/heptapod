<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Schema\Schema;

/** The cron addon describes task setup separately from its parent check config. */
interface TaskDefinitionSchemaProvider
{
    public function taskDefinitionSchema(): Schema;
}
