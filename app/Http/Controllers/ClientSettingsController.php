<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CreateClient;
use Heptapod\Actions\UpdateClient;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ClientSettingsPageData;
use Heptapod\Models\Client;
use Heptapod\Queries\ClientSettingsReader;
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
        Gate::authorize('create', Client::class);

        return Inertia::render('clients/settings', new ClientSettingsPageData(null, [], [], \DateTimeZone::listIdentifiers())->toArray());
    }

    public function store(Request $request, CreateClient $create): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', Client::class);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $client = $create($attributes);
        if ($request->expectsJson()) {
            return response()->json(ClientData::fromClient($client)->toArray(), 201);
        }

        return redirect()->route('clients.show', ['client' => $client->id]);
    }

    public function edit(Client $client, ClientSettingsReader $reader): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('clients/settings', $reader->read($client)->toArray());
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
