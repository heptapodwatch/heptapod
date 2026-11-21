<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\UnsnoozeCheck;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Illuminate\Http\Request;

#[Group('Checks')]
final class UnsnoozeCheckController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Check $check, UnsnoozeCheck $unsnooze, AuthorizeSiteWrite $authorize): CheckResource
    {
        $user = $this->principal($request);
        $authorize($user, 'snooze', static fn () => $unsnooze($check, $user), $check);

        return new CheckResource($check->load('snoozes'));
    }
}
