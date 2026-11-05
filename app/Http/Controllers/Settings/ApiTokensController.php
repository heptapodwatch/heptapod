<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Actions\RevokeApiToken;
use Heptapod\Data\Ui\ApiTokenSettingsData;
use Heptapod\Data\Ui\ApiTokensPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class ApiTokensController extends Controller
{
    public function edit(Request $request, CreateApiToken $create, SitePolicy $policy): Response
    {
        return Inertia::render('settings/api-tokens', new ApiTokensPageData(
            tokensPage: $this->data($request, $create, $policy),
        ));
    }

    public function index(Request $request, CreateApiToken $create, SitePolicy $policy): JsonResponse
    {
        return response()->json($this->data($request, $create, $policy));
    }

    private function data(Request $request, CreateApiToken $create, SitePolicy $policy): ApiTokenSettingsData
    {
        $user = $this->user($request);
        // Do not apply the topbar preference: token reach is constrained separately, including empty clients.
        // ClientPolicy denies client reads without a team role; self-token management has no such role gate.
        /** @var Collection<int, Client> $clients */
        $clients = $user->hasAnyRole(['Owner', 'Admin', 'Member'])
            ? $policy->visibleClients($user, includeArchived: true)->with(Client::AvatarRelations)->get()->sortBy('name')->values()
            : new Collection;
        /** @var array<int, string> $names */
        $names = $clients->pluck('name', 'id')->all();
        $tokens = $user->tokens()->getQuery()->get()->sortBy('id')->map(static function (PersonalAccessToken $token) use ($names): array {
            /** @var int|null $clientId */
            $clientId = $token->getAttribute('client_id');
            /** @var list<string> $abilities */
            $abilities = $token->abilities;

            return ['id' => $token->id, 'name' => $token->name, 'abilities' => $abilities,
                'client_id' => $clientId, 'client_name' => $clientId === null ? null : ($names[$clientId] ?? null),
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(), 'expires_at' => $token->expires_at?->toIso8601String()];
        })->all();

        return new ApiTokenSettingsData(array_values($tokens), CreateApiToken::Abilities, $create->presets($user),
            array_values($clients->map(static fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'short_name' => $client->short_name, 'accent_color' => $client->accent_color, 'avatar' => app(ResolveClientAvatar::class)($client), 'is_internal' => $client->is_internal])->all()), $user->timezone);
    }

    public function store(Request $request, CreateApiToken $create): JsonResponse
    {
        $user = $this->user($request);
        $presets = $create->presets($user);
        /** @var array{name: string, abilities?: list<string>, preset?: string, client_id?: int|null, expires_at?: string|null} $data */
        $data = Validator::make($request->all(), ['name' => ['required', 'string', 'max:255'],
            'abilities' => ['required_without:preset', 'prohibits:preset', 'array', 'min:1'], 'abilities.*' => ['required', 'string'],
            'preset' => ['required_without:abilities', Rule::in(array_keys($presets))],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'expires_at' => ['nullable', 'date', 'after:now']])->validate();
        $abilities = $data['abilities'] ?? $presets[$data['preset'] ?? ''] ?? throw new \LogicException('Token abilities are required.');
        $plainTextToken = $create($user, $data['name'], $abilities, $data['client_id'] ?? null,
            isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null);

        return response()->json(['token' => $plainTextToken], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $token, RevokeApiToken $revoke): JsonResponse
    {
        $revoke($this->user($request), $token);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
