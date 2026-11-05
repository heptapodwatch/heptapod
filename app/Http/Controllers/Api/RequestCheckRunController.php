<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\RequestCheckRun;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RequestCheckRunController extends Controller
{
    public function __invoke(Request $request, Check $check, RequestCheckRun $run): CheckResource
    {
        Gate::authorize('update', $check);
        /** @var array{httpClientHeaders?: list<array{name: string, value: string}>} $data */
        $data = Validator::make($request->all(), ['httpClientHeaders' => ['sometimes', 'array', 'max:5'], 'httpClientHeaders.*' => ['array:name,value'], 'httpClientHeaders.*.name' => ['required', 'string'], 'httpClientHeaders.*.value' => ['present', 'string']])->validate();
        $actor = $request->user();
        $run($check, RunOrigin::Manual, $data['httpClientHeaders'] ?? [], $actor instanceof User ? $actor : null, $actor instanceof User ? $actor->currentAccessToken()->id : null,
            heavyAdmission: false);

        return new CheckResource($check->refresh()->load('snoozes'));
    }
}
