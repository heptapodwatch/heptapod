<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\UnsnoozeCheck;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class UnsnoozeCheckController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Check $check, UnsnoozeCheck $unsnooze): CheckResource
    {
        Gate::authorize('snooze', $check);
        $user = $this->principal($request);
        $unsnooze($check, $user);

        return new CheckResource($check->load('snoozes'));
    }
}
