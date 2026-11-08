<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Account')]
final class MeController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, InstanceSettings $instance): JsonResponse
    {
        $user = $this->principal($request);

        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'photo_url' => url('/favicon.svg'), 'teams' => [['id' => 1, 'name' => $instance->name, 'timezone' => $instance->timezone, 'created_at' => $instance->installed_at]]]);
    }
}
