<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\CreateClient;
use Heptapod\Actions\UpdateClient;
use Heptapod\Concerns\RejectsUnsupportedFields;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\PaginationRequest;
use Heptapod\Http\Resources\ClientResource;
use Heptapod\Http\Resources\OhDear\PaginatedCollection;
use Heptapod\Models\Client;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Clients')]
final class ClientController extends Controller
{
    use RejectsUnsupportedFields, ResolvesApiPrincipal;

    /** The client fields the API writes; logos and site-icon avatars stay in the web app. */
    private const array Fields = ['name', 'short_name', 'locale', 'timezone', 'accent_color'];

    /**
     * The API keeps blank strings (bootstrap/app.php), which skip UpdateClient's non-implicit rules. As null they validate as
     * in the web form: a blank name, locale or accent color fails, a blank short name or time zone clears it.
     *
     * @return array<string, mixed>
     */
    private function clientAttributes(Request $request): array
    {
        $this->rejectUnsupportedFields($request->all(), self::Fields);

        /** @var array<string, mixed> $attributes */
        $attributes = array_map(static fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $request->only(self::Fields));

        return $attributes;
    }

    public function index(PaginationRequest $request, SitePolicy $policy): JsonResponse
    {
        $user = $this->principal($request);
        Validator::make($request->all(), ['filter' => ['sometimes', 'array:include_archived'], 'filter.include_archived' => ['sometimes', 'in:true,false,1,0']])->validate();
        $query = $policy->visibleClients($user, includeArchived: in_array($request->input('filter.include_archived'), ['true', '1'], true));
        $query->getQuery()->orderBy('clients.id');
        $paginator = $query->paginate($request->pageSize(), ['*'], $request->pageParameter(), $request->pageNumber())->appends($request->except($request->pageParameter() === 'page' ? 'page' : 'page.number'));

        return new PaginatedCollection($paginator, ClientResource::class)->response($request);
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource($client);
    }

    /** @throws ValidationException from the UpdateClient rules */
    public function store(Request $request, CreateClient $create): JsonResponse
    {
        $user = $this->principal($request, 403);
        Gate::authorize('create', Client::class);
        // A token limited to one client cannot widen its own reach.
        abort_if($user->tokenClientConstraint() !== null, 403);

        return new ClientResource($create($this->clientAttributes($request)))->response()->setStatusCode(201);
    }

    /** @throws ValidationException from the UpdateClient rules */
    public function update(Request $request, Client $client, UpdateClient $update): ClientResource
    {
        Gate::authorize('update', $client);

        return new ClientResource($update($client, $this->clientAttributes($request)));
    }
}
