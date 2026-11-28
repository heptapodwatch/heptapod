<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\ArchiveClient;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ArchiveClientController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, Client $client, ArchiveClient $archive): RedirectResponse
    {
        Gate::authorize('archive', $client);
        Validator::make($request->all(), [
            'site_action' => ['sometimes', Rule::in(['archive', 'move'])],
            'client_id' => ['required_if:site_action,move', 'nullable', 'integer', Rule::notIn([$client->id]), Rule::exists('clients', 'id')->whereNull('archived_at')],
        ])->validate();
        $archive($client, $user, $request->input('site_action') === 'move' ? $request->integer('client_id') : null);

        return redirect()->route('clients.index');
    }
}
