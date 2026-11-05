<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class MaintenanceReturnContext
{
    /** @return array{state?: string, site_id?: int|numeric-string, client_id?: int|numeric-string|null, page?: int|numeric-string} */
    public static function parameters(Request $request): array
    {
        /** @var array{return_context?: array{state?: string, site_id?: int|numeric-string, client_id?: int|numeric-string|null, page?: int|numeric-string}} $validated */
        $validated = Validator::make($request->query(), [
            'return_context' => 'sometimes|array:state,site_id,client_id,page',
            'return_context.state' => ['sometimes', Rule::in(['all', 'active', 'planned', 'done'])],
            'return_context.site_id' => 'sometimes|integer|min:1',
            'return_context.client_id' => 'sometimes|nullable|integer|min:1',
            'return_context.page' => 'sometimes|integer|between:1,10000',
        ])->validate();

        return $validated['return_context'] ?? [];
    }
}
