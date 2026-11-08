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

    /**
     * The errors keyed by the site forms' check settings field names (`checks.<type>.<field>`).
     *
     * @return array<string, mixed>
     */
    public function formErrors(): array
    {
        $errors = [];
        foreach ($this->errors() as $path => $messages) {
            $field = preg_replace('/^(?:values\.|'.preg_quote($this->check->value, '/').'_check_settings\.)/', '', $path);
            $errors['checks.'.$this->check->value.'.'.$field] = $messages;
        }

        return $errors;
    }
}
