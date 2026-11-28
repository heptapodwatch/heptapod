<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\CreateMaintenanceWindow;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Actions\UpdateMaintenancePeriod;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Data\Ui\MaintenanceIndexPageData;
use Heptapod\Data\Ui\MaintenanceTargetOptionData;
use Heptapod\Data\Ui\MaintenanceWindowData;
use Heptapod\Enums\Capability;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Http\MaintenanceReturnContext;
use Heptapod\Maintenance\LocalMaintenanceTime;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\MaintenanceReader;
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
        $siteName = null;
        $filters = ListFilters::read($request, [
            'client_id' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['nullable', 'integer', 'min:1']],
            'site_id' => ['kind' => FilterKind::Chip, 'label' => 'Site', 'rules' => ['integer', 'min:1'],
                'display' => static function () use (&$siteName): array {
                    /** @var string|null $siteName Set by the visibility-scoped list read before the props are built. */
                    return ['display' => $siteName ?? throw new \LogicException('The maintenance scope has no site name.')];
                }],
            'state' => ['kind' => FilterKind::Segmented, 'label' => 'Window state', 'default' => 'all', 'options' => ['all' => 'All', 'active' => 'Active', 'planned' => 'Planned', 'done' => 'Done']],
        ], ['page' => ['sometimes', 'integer', 'between:1,10000']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $state = $filters->value('state') ?? throw new \LogicException('The maintenance state has no default.');
        $result = $reader->index($user, $filters->integer('site_id'), $filters->integer('client_id'), $state);
        $siteName = $result['site_name'];
        $canManage = Capability::Manage->allows($user);
        // The rows name a targeted client with its mark; only the clients of the listed windows are read.
        $windowClientIds = array_values(array_unique(array_map(static fn (MaintenanceWindowData $window): int => $window->targetId,
            array_filter($result['windows'], static fn (MaintenanceWindowData $window): bool => $window->targetType === 'client'))));
        $windowClientQuery = $policy->visibleClients($user, includeArchived: true)->with(Client::AvatarRelations);
        $windowClientQuery->getQuery()->whereIntegerInRaw('clients.id', $windowClientIds);
        $windowClients = $windowClientIds === [] ? [] : $windowClientQuery->get()
            ->map(static fn (Client $client): MaintenanceTargetOptionData => new MaintenanceTargetOptionData($client->id, $client->displayName(), app(ResolveClientAvatar::class)($client), $client->short_name, $client->accent_color, $client->is_internal))->all();
        $tagOptions = Tag::query();
        $tagOptions->getQuery()->orderBy('id');
        $tags = $canManage ? $tagOptions->get()
            ->map(static fn (Tag $tag): MaintenanceTargetOptionData => new MaintenanceTargetOptionData($tag->id, $tag->name))->all() : [];

        $timezones = \DateTimeZone::listIdentifiers();
        $storedTimezones = [$user->resolvedTimezone()];
        if ($canManage) {
            // A new window follows its target client's zone, so every stored client zone stays choosable.
            /** @var list<string> $clientTimezones */
            $clientTimezones = Client::query()->getQuery()->whereNotNull('timezone')->distinct()->pluck('timezone')->all();
            array_push($storedTimezones, ...$clientTimezones);
        }
        foreach ($result['windows'] as $window) {
            $storedTimezones[] = $window->timezone;
            if ($window->seriesTimezone !== null) {
                $storedTimezones[] = $window->seriesTimezone;
            }
        }
        foreach (array_unique($storedTimezones) as $timezone) {
            if (! in_array($timezone, $timezones, true)
                && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                $timezones[] = $timezone;
            }
        }

        return Inertia::render('maintenance/index', new MaintenanceIndexPageData($result['windows'], $result['pagination'],
            $canManage, array_values($tags),
            $timezones,
            array_values($windowClients), $filters->data())->toArray());
    }

    public function show(Request $request, Site $site, MaintenanceReader $reader): JsonResponse
    {
        return response()->json(['window' => $reader->siteHeader($this->user($request), $site)?->toArray()]);
    }

    public function store(Request $request, CreateMaintenanceWindow $create, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $this->writable($request);
        $returnContext = MaintenanceReturnContext::parameters($request);
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $user = $this->user($request);
        $authorize->asManager($user, static fn (User $current): MaintenancePeriod|RecurringMaintenancePeriod => $create($current, $input));

        return to_route('maintenance.index', $returnContext);
    }

    public function update(Request $request, MaintenancePeriod $window, UpdateMaintenancePeriod $update,
        LocalMaintenanceTime $localTime, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $this->writable($request);
        // Answered before the form is validated, and decided again on the locked window.
        $this->refuseEnded($window, CarbonImmutable::now('UTC'));
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
        $start = $localTime->resolve(substr($data['starts_local'], 0, 10), substr($data['starts_local'], 11), $data['timezone']);
        $end = $localTime->resolve(substr($data['ends_local'], 0, 10), substr($data['ends_local'], 11), $data['timezone']);
        // The replacement target is validated under the access lock: a site deleted while the edit waited is gone by then.
        $authorize->asManager($this->user($request), function () use ($update, $window, $data, $start, $end): MaintenancePeriod {
            $this->validTarget($data['target_type'], $data['target_id']);

            return $update($window, $data['name'] ?? null, $start, $end, $data, $this->refuseEnded(...));
        });

        return to_route('maintenance.index', $returnContext);
    }

    public function destroy(Request $request, MaintenancePeriod $window, DeleteMaintenancePeriod $delete, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $this->writable($request);
        $returnContext = MaintenanceReturnContext::parameters($request);
        $authorize->asManager($this->user($request), fn () => $delete($window, $this->refuseStarted(...)));

        return to_route('maintenance.index', $returnContext);
    }

    /** Decided on the locked window (UpdateMaintenancePeriod): a window ended meanwhile is not reopened by an older form. */
    private function refuseEnded(MaintenancePeriod $window, CarbonImmutable $now): void
    {
        if ($window->ends_at <= $now) {
            throw new ExpectedRefusal(
                status: 409,
                messageKey: 'errors.maintenance.already_ended',
                errorCode: 'maintenance_already_ended',
                tone: 'info',
            );
        }
    }

    /** Decided on the locked window (DeleteMaintenancePeriod): a window that has started meanwhile is not deleted. */
    private function refuseStarted(MaintenancePeriod $window, CarbonImmutable $now): void
    {
        if ($window->starts_at <= $now) {
            throw new ExpectedRefusal(
                status: 409,
                messageKey: 'errors.maintenance.already_started',
                errorCode: 'maintenance_already_started',
            );
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function writable(Request $request): void
    {
        abort_unless(Capability::Manage->allows($this->user($request)), 403);
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
