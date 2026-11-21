<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\RequestCheckRun;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Check runs')]
final class RequestCheckRunController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, Check $check, RequestCheckRun $run, AuthorizeSiteWrite $authorize): CheckResource
    {
        Gate::authorize('update', $check);
        /** @var array{httpClientHeaders?: list<array{name: string, value: string}>} $data */
        $data = Validator::make($request->all(), ['httpClientHeaders' => ['sometimes', 'array', 'max:5'], 'httpClientHeaders.*' => ['array:name,value'], 'httpClientHeaders.*.name' => ['required', 'string'], 'httpClientHeaders.*.value' => ['present', 'string']])->validate();
        $actor = $this->principal($request);
        // The resource reads the check and site loaded under the lock: a site deleted after the commit must not fail the response.
        $authorize($actor, 'update', static function () use ($run, $check, $data, $actor): void {
            $run($check, RunOrigin::Manual, $data['httpClientHeaders'] ?? [], $actor, $actor->currentAccessToken()->id, heavyAdmission: false);
            $check->refresh()->load(['snoozes', 'site.checks']);
        }, $check);

        return new CheckResource($check);
    }
}
