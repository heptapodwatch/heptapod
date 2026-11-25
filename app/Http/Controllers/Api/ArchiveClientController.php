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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('Clients')]
final class ArchiveClientController extends Controller
{
    use RejectsUnsupportedFields, ResolvesApiPrincipal;

    public function __invoke(Request $request, Client $client, ArchiveClient $archive, SitePolicy $policy): ClientResource
    {
        Gate::authorize('archive', $client);
        $user = $this->principal($request);
        // The mode has no default: a missing, empty or malformed JSON body decodes to no fields and fails on `sites`.
        $input = $request->json()->all();
        $this->rejectUnsupportedFields($input, ['sites', 'move_to_client_id']);
        /** @var array{sites: 'pause'|'move', move_to_client_id?: int} $data */
        $data = Validator::make($input, ['sites' => ['required', 'string', Rule::in(['pause', 'move'])],
            // missing_unless, not prohibited_unless: a blank string passes prohibited and skips every non-implicit rule.
            'move_to_client_id' => ['required_if:sites,move', 'missing_unless:sites,move', 'integer:strict', 'not_in:'.$client->id, $policy->visibleClientRule($user)]])->validate();
        try {
            $archive($client, $user, $data['sites'] === 'move' ? $data['move_to_client_id'] ?? throw new \LogicException('Validated move needs a target.') : null);
        } catch (ValidationException $exception) {
            // The action names the web form's fields (site_action, client_id); the API caller sent move_to_client_id.
            throw ValidationException::withMessages(['move_to_client_id' => $exception->validator->errors()->all()]);
        }

        return new ClientResource($client->refresh());
    }
}
