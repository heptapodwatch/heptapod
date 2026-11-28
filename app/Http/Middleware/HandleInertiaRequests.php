<?php

namespace Heptapod\Http\Middleware;

use Heptapod\Contracts\Export\Table;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Data\Ui\RunningNowData;
use Heptapod\Enums\Capability;
use Heptapod\Enums\RecipientKind;
use Heptapod\Live\LiveChannels;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Queries\RunningNowReader;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\ShellHealthReader;
use Heptapod\Queries\UpdateStatusReader;
use Heptapod\SystemHealth\SystemIssueKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    #[\Override]
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $request->session()->forget('selected_client_id');
        $user = $request->user();
        // The user payload below is built now, not at response time: its roles (the user menu) must already be loaded.
        if ($user instanceof User) {
            $user->loadMissing('roles');
        }

        return [
            ...parent::share($request),
            'csrfToken' => Inertia::always(static fn (): string => $request->session()->token()),
            'locale' => app()->getLocale(),
            'supportedLocales' => Inertia::once(static function (): array {
                /** @var list<string> $supported */
                $supported = config()->array('app.supported_locales');

                return array_map(static function (string $code): array {
                    $name = \Locale::getDisplayName($code, $code);
                    if ($name === false) {
                        throw new \LogicException('No display language for locale '.$code.'.');
                    }

                    return ['code' => $code, 'name' => $name];
                }, $supported);
            }),
            // The viewer's zone: their own, else the instance's (guests too); lazy so non-Inertia responses stay zero-DB.
            'timezone' => static fn (): string => User::viewerTimezone($user instanceof User ? $user : null),
            'exportRowLimit' => Table::RowLimit,
            'sourceUrl' => config()->string('heptapod.updates.source_url'),
            'mailLogOnly' => MailDeliveryMode::logOnly(),
            'mailConfigurationAction' => SystemIssueKind::MailLogOnly->navigationAction($user instanceof User ? $user : null),
            'mailMayOnlyBeLogged' => MailDeliveryMode::includesLocalTransport(),
            'auth' => [
                // Like ClientData: `timezone` is the zone in use, `timezone_override` the user's own choice (null follows the instance).
                'user' => $user instanceof User ? [...$user->toArray(), 'timezone' => $user->resolvedTimezone(), 'timezone_override' => $user->timezone] : $user,
                'canManageMembers' => $user instanceof User && Capability::Manage->allows($user),
                'canManageInstance' => $user instanceof User && Capability::ManageInstance->allows($user),
                'canViewSystemHealth' => $user instanceof User && Capability::Manage->allows($user),
                'canViewUpdates' => $user instanceof User && Capability::Manage->allows($user),
                'canManageChannels' => $user instanceof User && Capability::Manage->allows($user),
                // Lazy, and the role before the switch: only Owners and Admins of an instance that allows servers read the setting.
                'canViewServers' => static fn (): bool => $user instanceof User && app(ServersAccess::class)->allows($user)
                    && app(ServersAccess::class)->enabled(),
            ],
            // The installed version for the help dialog; release checks stay with Owners and Admins, who may open Updates & backups.
            'heptapodVersion' => $user instanceof User ? config()->string('heptapod.version') : null,
            // A cache read only (no query on any page); null until a check or the Updates page wrote it.
            'appUpdate' => static fn (): ?array => $user instanceof User && Capability::Manage->allows($user)
                ? app(UpdateStatusReader::class)->shellSummary() : null,
            'overallHealth' => fn (): ?array => $user instanceof User ? app(ShellHealthReader::class)->read($user)->toArray() : null,
            ...($user instanceof User && Capability::ViewInstance->allows($user)
                // Inline, not deferred: a deferred group costs every visit a second request that reruns the page controller.
                ? ['lateRunsCount' => static fn (): int => app(RunsReader::class)->lateCount($user),
                    // Inline for the same reason: a hard reload shows the viewer's runs at once.
                    'runningNow' => static fn (): RunningNowData => app(RunningNowReader::class)->forUser($user)]
                : []),
            // Channels the viewer can repair: the team's and clients' for Owners and Admins, and always their own.
            'notificationAttention' => fn (): array => $user instanceof User && $user->disabled_at === null
                ? NotificationDestination::query()->where('needs_attention', true)
                    ->where(fn (Builder $mine): Builder => $mine->where('user_id', $user->id)
                        ->when(Capability::Manage->allows($user), fn (Builder $managed): Builder => $managed->orWhere('recipient_kind', RecipientKind::Team)
                            ->orWhere('recipient_kind', RecipientKind::Client)))
                    ->where('removed_at', null)->get(['id', 'label', 'recipient_kind', 'client_id', 'attention_reason'])
                    ->filter(static fn (NotificationDestination $destination): bool => $destination->recipient_kind !== RecipientKind::Client
                        || Capability::ViewClient->allows($user, $destination->client_id ?? 0))
                    ->map(static fn (NotificationDestination $destination): array => ['id' => $destination->id, 'label' => $destination->label,
                        'reason' => __($destination->attention_reason ?? throw new \LogicException('A destination needing attention must have a reason.')),
                    ])->values()->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'liveChannels' => static fn (): array => $user instanceof User ? app(LiveChannels::class)->forUser($user) : [],
        ];
    }
}
