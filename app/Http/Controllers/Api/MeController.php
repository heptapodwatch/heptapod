<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

#[Group('Account')]
final class MeController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, InstanceSettings $instance): JsonResponse
    {
        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'photo_url' => url('/favicon.svg'), 'teams' => [['id' => 1, 'name' => $instance->name, 'timezone' => $instance->timezone, 'created_at' => $instance->installed_at]]]);
    }
}
