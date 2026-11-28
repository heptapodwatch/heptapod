<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RestoreClient;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RestoreClientController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, Client $client, RestoreClient $restore): RedirectResponse
    {
        Gate::authorize('restore', $client);
        Validator::make($request->all(), ['resume_sites' => ['sometimes', 'boolean']])->validate();
        $restore($client, $request->boolean('resume_sites', true), $user);

        return back();
    }
}
