<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\DisableCheck;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DisableCheckController extends Controller
{
    public function __invoke(Check $check, DisableCheck $disable): JsonResponse
    {
        Gate::authorize('update', $check);
        $disable($check);

        return response()->json(['id' => $check->id, 'type' => $check->type->value, 'enabled' => $check->enabled]);
    }
}
