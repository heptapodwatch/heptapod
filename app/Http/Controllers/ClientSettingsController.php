<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CreateClient;
use Heptapod\Actions\UpdateClient;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientSettingsPageData;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Queries\ClientSettingsReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ClientSettingsController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('clients/settings', new ClientSettingsPageData(null, [], false, \DateTimeZone::listIdentifiers())->toArray())
            ->with('instanceTimezone', app(InstanceSettings::class)->timezone);
    }

    public function store(Request $request, CreateClient $create): RedirectResponse|JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $client = $create($attributes);
        if ($request->expectsJson()) {
            return response()->json(ClientData::fromClient($client)->toArray(), 201);
        }

        return redirect()->route('clients.show', ['client' => $client->id]);
    }

    public function edit(#[CurrentUser] User $user, Client $client, ClientSettingsReader $reader): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('clients/settings', $reader->read($client, $user)->toArray())->with('instanceTimezone', app(InstanceSettings::class)->timezone);
    }

    public function update(Request $request, Client $client, UpdateClient $update): RedirectResponse
    {
        Gate::authorize('update', $client);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $update($client, $attributes);

        return back();
    }
}
