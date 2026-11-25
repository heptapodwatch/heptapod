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
use Illuminate\Validation\Rule;

#[Group('Clients')]
final class RestoreClientController extends Controller
{
    use RejectsUnsupportedFields, ResolvesApiPrincipal;

    public function __invoke(Request $request, Client $client, RestoreClient $restore): ClientResource
    {
        Gate::authorize('restore', $client);
        // Resuming changes monitoring, so it has no default: a missing, empty or malformed JSON body fails on `sites`.
        $input = $request->json()->all();
        $this->rejectUnsupportedFields($input, ['sites']);
        /** @var array{sites: 'resume'|'keep_paused'} $data */
        $data = Validator::make($input, ['sites' => ['required', 'string', Rule::in(['resume', 'keep_paused'])]])->validate();
        $restore($client, $data['sites'] === 'resume', $this->principal($request));

        return new ClientResource($client->refresh());
    }
}
