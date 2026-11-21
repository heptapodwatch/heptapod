<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\DisableCheck;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Checks')]
final class DisableCheckController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Check $check, DisableCheck $disable, AuthorizeSiteWrite $authorize): JsonResponse
    {
        // Early and unlocked, so a hidden check answers 404 like a missing one even while the access lock is busy.
        Gate::authorize('update', $check);
        $authorize($this->principal($request), 'update', static fn () => $disable($check), $check);

        return response()->json(['id' => $check->id, 'type' => $check->type->value, 'enabled' => $check->enabled]);
    }
}
