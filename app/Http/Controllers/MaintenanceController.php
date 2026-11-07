<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateMaintenanceWindow;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Actions\UpdateMaintenancePeriod;
use Heptapod\Data\Ui\MaintenanceIndexPageData;
use Heptapod\Data\Ui\MaintenanceTargetOptionData;
use Heptapod\Http\MaintenanceReturnContext;
use Heptapod\Maintenance\LocalMaintenanceTime;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\MaintenanceReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Tags\Tag;

final class MaintenanceController extends Controller
{
    public function index(Request $request, MaintenanceReader $reader, SitePolicy $policy): Response
    {
        /** @var array{page?: int|numeric-string, site_id?: int|numeric-string, client_id?: int|numeric-string|null, state?: string} $filters */
        $filters = Validator::make($request->all(), [
            'page' => 'sometimes|integer|between:1,10000', 'site_id' => 'sometimes|integer|min:1',
            'client_id' => 'sometimes|nullable|integer|min:1',
            'state' => ['sometimes', Rule::in(['all', 'active', 'planned', 'done'])],
        ])->validate();
        $user = $this->user($request);
        $siteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $state = $filters['state'] ?? 'all';
        $result = $reader->index($user, (int) ($filters['page'] ?? 1), $siteId, $clientId, $state);
        $canManage = $user->hasAnyRole(['Owner', 'Admin']);
        $siteOptions = Site::query();
        $siteOptions->getQuery()->orderBy('id');
        $sites = $canManage ? $siteOptions->with('client:id,name,is_internal')->get(['id', 'url', 'friendly_name', 'client_id', 'icon_hash', 'icon_backing'])
            ->map(static fn (Site $site): MaintenanceTargetOptionData => new MaintenanceTargetOptionData($site->id, $site->displayName(), client_name: $site->client !== null ? $site->client->displayName() : null, host: is_string(parse_url($site->url, PHP_URL_HOST)) ? parse_url($site->url, PHP_URL_HOST) : $site->url, icon_url: $site->iconUrl(), icon_backing: $site->icon_backing))->all() : [];
        $clientOptions = ($canManage ? Client::query() : $policy->visibleClients($user, includeArchived: true))->with(Client::AvatarRelations);
        $clientOptions->getQuery()->orderBy('name');
        $loadedClients = $clientOptions->get(['id', 'name', 'short_name', 'accent_color', 'avatar_site_id', 'is_internal']);
        $identities = $loadedClients->mapWithKeys(static fn (Client $client): array => [$client->id => new MaintenanceTargetOptionData($client->id, $client->displayName(), app(ResolveClientAvatar::class)($client), $client->short_name, $client->accent_color, $client->is_internal)]);
        $clients = $canManage ? $identities->values()->all() : [];
        // Reuse the resolved identity; browsing alone hides an empty Internal client.
        $filterClients = $loadedClients->filter(static fn (Client $client): bool => ! $client->is_internal || $client->sites->isNotEmpty())
            ->map(static fn (Client $client): MaintenanceTargetOptionData => $identities[$client->id] ?? throw new \LogicException('Every loaded client must have one resolved identity.'))->values()->all();
        $tagOptions = Tag::query();
        $tagOptions->getQuery()->orderBy('id');
        $tags = $canManage ? $tagOptions->get()
            ->map(static fn (Tag $tag): MaintenanceTargetOptionData => new MaintenanceTargetOptionData($tag->id, $tag->name))->all() : [];

        $instanceTimezone = app(InstanceSettings::class)->timezone;
        $timezones = \DateTimeZone::listIdentifiers();
        $storedTimezones = [$instanceTimezone];
        foreach ($result['windows'] as $window) {
            $storedTimezones[] = $window->timezone;
            if ($window->seriesTimezone !== null) {
                $storedTimezones[] = $window->seriesTimezone;
            }
        }
        foreach ($storedTimezones as $timezone) {
            if (! in_array($timezone, $timezones, true)
                && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                $timezones[] = $timezone;
            }
        }

        return Inertia::render('maintenance/index', new MaintenanceIndexPageData($result['windows'], $result['next_page'],
            $canManage, $user->timezone, array_values($sites), array_values($clients), array_values($tags),
            $instanceTimezone, $timezones,
            array_values($filterClients), $clientId, $siteId, $result['site_name'], $state,
            $result['previous_page'], $result['total'])->toArray());
    }

    public function show(Request $request, Site $site, MaintenanceReader $reader): JsonResponse
    {
        return response()->json(['window' => $reader->siteHeader($this->user($request), $site)?->toArray()]);
    }

    public function store(Request $request, CreateMaintenanceWindow $create): RedirectResponse
    {
        $this->writable($request);
        $returnContext = MaintenanceReturnContext::parameters($request);
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $create($this->user($request), $input);

        return to_route('maintenance.index', $returnContext);
    }

    public function update(Request $request, MaintenancePeriod $window, UpdateMaintenancePeriod $update,
        LocalMaintenanceTime $localTime): RedirectResponse
    {
        $this->writable($request);
        abort_unless($window->ends_at > CarbonImmutable::now('UTC'), 409);
        $returnContext = MaintenanceReturnContext::parameters($request);
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255', 'reason' => 'nullable|string|max:255',
            'starts_local' => 'required|date_format:Y-m-d H:i', 'ends_local' => 'required|date_format:Y-m-d H:i',
            'timezone' => 'required|timezone:all', 'mute_alerts' => 'required|boolean', 'catch_up_at_end' => 'required|boolean',
            'target_type' => ['required', Rule::in(['site', 'client', 'tag'])], 'target_id' => 'required|integer|min:1',
        ])->validate();
        /** @var array{target_type: 'site'|'client'|'tag', target_id: int, starts_local: string, ends_local: string,
         * timezone: string, name?: string|null, reason?: string|null, mute_alerts: bool, catch_up_at_end: bool} $data
         */
        $this->validTarget($data['target_type'], $data['target_id']);
        $start = $localTime->resolve(substr($data['starts_local'], 0, 10), substr($data['starts_local'], 11), $data['timezone']);
        $end = $localTime->resolve(substr($data['ends_local'], 0, 10), substr($data['ends_local'], 11), $data['timezone']);
        $update($window, $data['name'] ?? null, $start, $end, $data);

        return to_route('maintenance.index', $returnContext);
    }

    public function destroy(Request $request, MaintenancePeriod $window, DeleteMaintenancePeriod $delete): RedirectResponse
    {
        $this->writable($request);
        abort_unless($window->starts_at > CarbonImmutable::now('UTC'), 409);
        $returnContext = MaintenanceReturnContext::parameters($request);
        $delete($window);

        return to_route('maintenance.index', $returnContext);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function writable(Request $request): void
    {
        abort_unless($this->user($request)->hasAnyRole(['Owner', 'Admin']), 403);
    }

    private function validTarget(string $type, int $id): void
    {
        $table = match ($type) {
            'site' => 'sites', 'client' => 'clients', 'tag' => 'tags',
            default => throw new \InvalidArgumentException('Unknown maintenance target type.'),
        };
        if (! DB::table($table)->where('id', $id)->exists()) {
            throw ValidationException::withMessages(['target_id' => __('validation.exists', ['attribute' => __('Target')])]);
        }
    }
}
