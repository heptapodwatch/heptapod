<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\EnableCheck;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class EnableCheckController extends Controller
{
    public function __invoke(Check $check, EnableCheck $enable): JsonResponse
    {
        Gate::authorize('update', $check);
        $enable($check);

        return response()->json(['id' => $check->id, 'type' => $check->type->value, 'enabled' => $check->enabled]);
    }
}
