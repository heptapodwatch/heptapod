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

#[Group('Checks')]
final class DisableCheckController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Check $check, DisableCheck $disable, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $authorize($this->principal($request), 'update', static fn () => $disable($check), $check);

        return response()->json(['id' => $check->id, 'type' => $check->type->value, 'enabled' => $check->enabled]);
    }
}
