<?php

declare(strict_types=1);

namespace Heptapod\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

final class ActiveUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        $query = parent::newModelQuery($model);
        $query->getQuery()->whereNull('disabled_at');

        return $query;
    }
}
