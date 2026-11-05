<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PaginationRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return is_array($this->query('page')) ? ['page' => ['array:number,size'], 'page.number' => ['sometimes', 'integer', 'min:1'], 'page.size' => ['sometimes', 'integer', 'min:1', 'max:200'], 'per_page' => ['prohibited']]
            : ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200']];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->server->getString('QUERY_STRING');
        $names = array_map(fn (string $pair): string => urldecode(explode('=', $pair, 2)[0]), explode('&', $query));
        $brackets = count(array_filter($names, fn (string $name): bool => str_starts_with($name, 'page['))) > 0;
        if ($brackets && (in_array('page', $names, true) || in_array('per_page', $names, true))) {
            throw ValidationException::withMessages(['page' => __('api.mixed_pagination')]);
        }
    }

    public function pageNumber(): int
    {
        return is_array($this->query('page')) ? $this->integer('page.number', 1) : $this->integer('page', 1);
    }

    public function pageSize(): int
    {
        return is_array($this->query('page')) ? $this->integer('page.size', 200) : $this->integer('per_page', 200);
    }

    public function pageParameter(): string
    {
        return is_array($this->query('page')) ? 'page[number]' : 'page';
    }
}
