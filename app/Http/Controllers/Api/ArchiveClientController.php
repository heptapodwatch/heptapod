<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\ArchiveClient;
use Heptapod\Concerns\RejectsUnsupportedFields;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\ClientResource;
use Heptapod\Models\Client;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Clients')]
final class ArchiveClientController extends Controller
{
    use RejectsUnsupportedFields, ResolvesApiPrincipal;

    public function __invoke(Request $request, Client $client, ArchiveClient $archive, SitePolicy $policy): ClientResource
    {
        Gate::authorize('archive', $client);
        $user = $this->principal($request);
        // A web-form key such as client_id must not fall back to pausing every site.
        $this->rejectUnsupportedFields($request, ['move_to_client_id']);
        /** @var array{move_to_client_id?: int|numeric-string|null} $data */
        $data = Validator::make($request->all(), ['move_to_client_id' => ['nullable', 'integer', 'not_in:'.$client->id, $policy->visibleClientRule($user)]])->validate();
        try {
            $archive($client, $user, isset($data['move_to_client_id']) ? (int) $data['move_to_client_id'] : null);
        } catch (ValidationException $exception) {
            // The action names the web form's fields (site_action, client_id); the API caller sent move_to_client_id.
            throw ValidationException::withMessages(['move_to_client_id' => $exception->validator->errors()->all()]);
        }

        return new ClientResource($client->refresh());
    }
}
