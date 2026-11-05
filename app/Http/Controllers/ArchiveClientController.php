<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\ArchiveClient;
use Heptapod\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class ArchiveClientController extends Controller
{
    public function __invoke(Client $client, ArchiveClient $archive): RedirectResponse
    {
        Gate::authorize('archive', $client);
        $archive($client);

        return redirect()->route('clients.index');
    }
}
