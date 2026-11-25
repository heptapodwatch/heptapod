<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\RestoreClient;
use Heptapod\Concerns\RejectsUnsupportedFields;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\ClientResource;
use Heptapod\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Clients')]
final class RestoreClientController extends Controller
{
    use RejectsUnsupportedFields, ResolvesApiPrincipal;

    public function __invoke(Request $request, Client $client, RestoreClient $restore): ClientResource
    {
        Gate::authorize('restore', $client);
        $this->rejectUnsupportedFields($request, ['resume_sites']);
        Validator::make($request->all(), ['resume_sites' => ['sometimes', 'boolean']])->validate();
        $restore($client, $request->boolean('resume_sites', true), $this->principal($request));

        return new ClientResource($client->refresh());
    }
}
