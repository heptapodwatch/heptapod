<?php

declare(strict_types=1);

namespace Heptapod\Exceptions;

use Heptapod\Contracts\CheckKey;
use Illuminate\Validation\ValidationException;

final class CheckConfigurationValidationException extends ValidationException
{
    public function __construct(public readonly CheckKey $check, ValidationException $exception)
    {
        parent::__construct($exception->validator, $exception->response, $exception->errorBag);
        $this->status = $exception->status;
        $this->redirectTo = $exception->redirectTo;
    }
}
