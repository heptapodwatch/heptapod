<?php

namespace Heptapod\Http\Middleware;

use Heptapod\Contracts\Export\Table;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Data\Ui\RunningNowData;
use Heptapod\Enums\RecipientKind;
use Heptapod\Live\LiveChannels;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Queries\RunningNowReader;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\ShellHealthReader;
use Heptapod\Settings\InstanceSettings;
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
            // The viewer's zone: their own, else the instance's (guests too); lazy so non-Inertia responses stay zero-DB.
            'timezone' => static fn (): string => $user instanceof User ? $user->resolvedTimezone() : app(InstanceSettings::class)->timezone,
            'exportRowLimit' => Table::RowLimit,
            'mailLogOnly' => MailDeliveryMode::logOnly(),
            'mailConfigurationAction' => SystemIssueKind::MailLogOnly->navigationAction($user instanceof User ? $user : null),
            'mailMayOnlyBeLogged' => MailDeliveryMode::includesLocalTransport(),
            'auth' => [
                // Like ClientData: `timezone` is the zone in use, `timezone_override` the user's own choice (null follows the instance).
                'user' => $user instanceof User ? [...$user->toArray(), 'timezone' => $user->resolvedTimezone(), 'timezone_override' => $user->timezone] : $user,
                'canManageMembers' => $user instanceof User && $user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin']),
                'canManageInstance' => $user instanceof User && $user->hasRole('Owner'),
                'canViewSystemHealth' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
                'canViewUpdates' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
                'canManageChannels' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
            ],
            'overallHealth' => fn (): ?array => $user instanceof User ? app(ShellHealthReader::class)->read($user)->toArray() : null,
            ...($user instanceof User && $user->hasAnyRole(['Owner', 'Admin', 'Member'])
                // Inline, not deferred: a deferred group costs every visit a second request that reruns the page controller.
                ? ['lateRunsCount' => static fn (): int => $user->disabled_at === null ? app(RunsReader::class)->lateCount($user) : 0,
                    // Inline for the same reason: a hard reload shows the viewer's runs at once.
                    'runningNow' => static fn (): ?RunningNowData => $user->disabled_at === null ? app(RunningNowReader::class)->forUser($user) : null]
                : []),
            // Channels the viewer can repair: the team's and clients' for Owners and Admins, and always their own.
            'notificationAttention' => fn (): array => $user instanceof User && $user->disabled_at === null
                ? NotificationDestination::query()->where('needs_attention', true)
                    ->where(fn (Builder $mine): Builder => $mine->where('user_id', $user->id)
                        ->when($user->hasAnyRole(['Owner', 'Admin']), fn (Builder $managed): Builder => $managed->orWhere('recipient_kind', RecipientKind::Team)
                            ->orWhere('recipient_kind', RecipientKind::Client)))
                    ->where('removed_at', null)->get(['id', 'label', 'recipient_kind', 'client_id', 'attention_reason'])
                    ->filter(static fn (NotificationDestination $destination): bool => $destination->recipient_kind !== RecipientKind::Client
                        || $user->canAccessClient($destination->client_id ?? 0))
                    ->map(static fn (NotificationDestination $destination): array => ['id' => $destination->id, 'label' => $destination->label,
                        'reason' => __($destination->attention_reason ?? throw new \LogicException('A destination needing attention must have a reason.')),
                    ])->values()->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'liveChannels' => static fn (): array => $user instanceof User ? app(LiveChannels::class)->forUser($user) : [],
        ];
    }
}
