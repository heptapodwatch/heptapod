<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

use Heptapod\Data\Ui\IconRefreshResultData;

final class IconRefreshFailed extends \RuntimeException
{
    public function __construct(public readonly IconRefreshResultData $result, ?\Throwable $previous = null)
    {
        parent::__construct($result->key, previous: $previous);
    }
}
