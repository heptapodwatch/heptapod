<?php

namespace Heptapod\Http\Middleware;

use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\ShellHealthReader;
use Heptapod\Queries\SitesReader;
use Heptapod\Queries\UpcomingReader;
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

        return [
            ...parent::share($request),
            'csrfToken' => Inertia::always(static fn (): string => $request->session()->token()),
            'locale' => app()->getLocale(),
            'timezone' => $user?->getAttribute('timezone') ?? config('app.timezone'),
            'name' => 'Heptapod',
            'mailLogOnly' => MailDeliveryMode::logOnly(),
            'mailConfigurationAction' => SystemIssueKind::MailLogOnly->navigationAction($user instanceof User ? $user : null),
            'mailMayOnlyBeLogged' => MailDeliveryMode::includesLocalTransport(),
            'auth' => [
                'user' => $user,
                'canManageMembers' => $user instanceof User && $user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin']),
                'canManageInstance' => $user instanceof User && $user->hasRole('Owner'),
                'canViewSystemHealth' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
                'canViewUpdates' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
                'canManageChannels' => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin']),
            ],
            'overallHealth' => fn (): ?array => $user instanceof User ? app(ShellHealthReader::class)->read($user)->toArray() : null,
            ...($user instanceof User && $user->hasAnyRole(['Owner', 'Admin', 'Member'])
                ? [
                    'upcomingCount' => Inertia::defer(static fn (): int => app(UpcomingReader::class)->count($user), 'sidebar'),
                    'lateRunsCount' => Inertia::defer(static fn (): int => $user->disabled_at === null ? app(RunsReader::class)->lateCount($user) : 0, 'sidebar'),
                ]
                : []),
            // Channels the viewer can repair: the team's and clients' for Owners and Admins, and always their own.
            'notificationAttention' => fn (): array => $user instanceof User && $user->disabled_at === null
                ? NotificationDestination::query()->where('needs_attention', true)
                    ->where(fn (Builder $mine): Builder => $mine->where('user_id', $user->id)
                        ->when($user->hasAnyRole(['Owner', 'Admin']), fn (Builder $managed): Builder => $managed->orWhere('recipient_kind', RecipientKind::Team)
                            ->orWhere('recipient_kind', RecipientKind::Client)))
                    ->where('removed_at', null)->get(['id', 'label', 'channel', 'recipient_kind', 'client_id', 'attention_reason'])
                    ->filter(static fn (NotificationDestination $destination): bool => $destination->recipient_kind !== RecipientKind::Client
                        || $user->canAccessClient($destination->client_id ?? 0))
                    ->map(static fn (NotificationDestination $destination): array => ['id' => $destination->id, 'label' => $destination->label, 'channel' => $destination->channel,
                        'recipient_kind' => $destination->recipient_kind->value, 'client_id' => $destination->client_id,
                        'reason' => __($destination->attention_reason ?? throw new \LogicException('A destination needing attention must have a reason.')),
                    ])->values()->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'liveChannels' => static fn (): array => $user instanceof User && $user->hasAnyRole(['Owner', 'Admin', 'Member'])
                ? ($user->hasAnyRole(['Owner', 'Admin']) || $user->client_scope === ClientScope::All
                    ? ['instance']
                    : array_map(static fn (int $clientId): string => 'client.'.$clientId, app(SitesReader::class)->clientIds($user)))
                : [],
        ];
    }
}
