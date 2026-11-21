<?php

namespace Heptapod\Providers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\RecordBackupOutcome;
use Heptapod\Actions\RecordRoleChange;
use Heptapod\Actions\StoreAvatarMetadata;
use Heptapod\Auth\ActiveUserProvider;
use Heptapod\Checks\BundledPublicSuffixList;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\DatabaseSiteCheckSettingsReader;
use Heptapod\Checks\History\DatabaseHistoryReader;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Checks\PostgresDatabaseClock;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Checks\RouteCronPingUrl;
use Heptapod\Checks\SnoozeNotificationSuppression;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CronPingUrl;
use Heptapod\Contracts\DailyRollupReader;
use Heptapod\Contracts\DiskSpaceReader;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\Http\DeliveryHttp;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\SameSite;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\LiveConnectionTerminator;
use Heptapod\Contracts\Mail\MailBrandingSource;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Contracts\PublicSuffixList;
use Heptapod\Contracts\SiteCheckSettingsReader;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\OutboxRecorded;
use Heptapod\Http\ErrorResponses;
use Heptapod\Http\Outbound\GuardedDeliveryHttp;
use Heptapod\Http\Outbound\GuzzleOutboundHttp;
use Heptapod\Http\Outbound\GuzzlePacedHttpClient;
use Heptapod\Http\Outbound\NativePrivateTargetGuard;
use Heptapod\Http\Outbound\NativeTargetAddressPolicy;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Heptapod\Http\Outbound\SameSitePolicy;
use Heptapod\Http\RequestListPaging;
use Heptapod\Jobs\BroadcastLiveSignal;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Live\LiveChannels;
use Heptapod\Live\ReverbConnectionTerminator;
use Heptapod\Mail\CapabilityRedactingMailLogger;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Event as OutboxEvent;
use Heptapod\Models\Incident;
use Heptapod\Models\Invitation;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\MaintenanceSiteExclusion;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Observers\CheckLiveObserver;
use Heptapod\Observers\ClientLiveObserver;
use Heptapod\Observers\IncidentLiveObserver;
use Heptapod\Observers\SiteLiveObserver;
use Heptapod\Queries\GenericSiteIcons;
use Heptapod\Settings\InstanceMailBranding;
use Heptapod\Support\AreaTranslationLoader;
use Heptapod\Support\AtomicRateLimiter;
use Heptapod\Support\OhDear\SdkOhDearClientFactory;
use Heptapod\SystemHealth\CheckFreshnessIssues;
use Heptapod\SystemHealth\MailTransportRecord;
use Heptapod\SystemHealth\NativeDiskSpaceReader;
use Heptapod\SystemHealth\PublishWorkerMemory;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Psr\Log\LoggerInterface;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public const string ScheduledCommandContextKey = 'heptapod.scheduled-command';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $langPath = $this->app->langPath();
        $this->app->extend('translation.loader', static fn (Loader $loader): AreaTranslationLoader => new AreaTranslationLoader($loader, $langPath));
        // The deferred cache provider registers the framework limiter; extend it so its later registration cannot replace ours.
        $this->app->extend(CacheRateLimiter::class, static fn (CacheRateLimiter $limiter, Application $app): AtomicRateLimiter => new AtomicRateLimiter(
            $app->make(CacheManager::class)->store(config()->string('cache.limiter', config()->string('cache.default'))),
        ));
        $this->app->alias(CacheRateLimiter::class, AtomicRateLimiter::class);
        $this->app->singleton(CheckRegistry::class);
        $this->app->bind(DiskSpaceReader::class, NativeDiskSpaceReader::class);
        $this->app->bind(ListPaging::class, RequestListPaging::class);
        $this->app->bind(MailBrandingSource::class, InstanceMailBranding::class);
        $this->app->bind(DatabaseClock::class, PostgresDatabaseClock::class);
        $this->app->scoped(GenericSiteIcons::class);
        // Alert keys depend on the installed registry and config for this request only.
        $this->app->singleton(PublicSuffixList::class, BundledPublicSuffixList::class);
        $this->app->singleton(SameSite::class, SameSitePolicy::class);
        $this->app->bind(SiteCheckSettingsReader::class, DatabaseSiteCheckSettingsReader::class);
        $this->app->bind(DeliveryHttp::class, GuardedDeliveryHttp::class);
        $this->app->bind(OutboundHttp::class, GuzzleOutboundHttp::class);
        $this->app->bind(PacedHttpClient::class, GuzzlePacedHttpClient::class);
        $this->app->bind(PrivateTargetGuard::class, NativePrivateTargetGuard::class);
        $this->app->bind(TargetResolver::class, NativeTargetResolver::class);
        $this->app->bind(TargetAddressPolicy::class, NativeTargetAddressPolicy::class);
        $this->app->bind(NotificationSuppression::class, SnoozeNotificationSuppression::class);
        $this->app->bind(LiveConnectionTerminator::class, ReverbConnectionTerminator::class);
        $this->app->bind(HistoryReader::class, DatabaseHistoryReader::class);
        $this->app->bind(DailyRollupReader::class, RollupReader::class);
        $this->app->bind(CronPingUrl::class, RouteCronPingUrl::class);
        $this->app->bind(ResultRecorder::class, TransactionalResultRecorder::class);
        $this->app->bind(OhDearClientFactory::class, SdkOhDearClientFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RetentionPolicy::fromConfig();
        $this->app->booted(static fn () => app(CheckFreshnessIssues::class)->validateConfiguration());
        Mail::extend('log', function (array $config): LogTransport {
            /** @var LoggerInterface $logger */
            $logger = $this->app->get(LoggerInterface::class);
            if ($logger instanceof LogManager) {
                /** @var string|null $channel */
                $channel = $config['channel'] ?? config('mail.log_channel');
                $logger = $logger->channel($channel);
            }

            return new LogTransport(new CapabilityRedactingMailLogger($logger));
        });
        $rootUrl = config()->string('app.url');
        if (parse_url($rootUrl, PHP_URL_SCHEME) === 'https') {
            URL::useOrigin($rootUrl);
            URL::forceScheme('https');
        }
        Auth::provider('active-eloquent', static function (Application $app, array $config): ActiveUserProvider {
            $model = $config['model'] ?? null;
            if (! is_string($model)) {
                throw new \LogicException('The authentication model must be configured.');
            }

            return new ActiveUserProvider($app->make(Hasher::class), $model);
        });
        $this->configureDefaults();
        Check::observe(CheckLiveObserver::class);
        Client::observe(ClientLiveObserver::class);
        Site::observe(SiteLiveObserver::class);
        Incident::observe(IncidentLiveObserver::class);
        Event::listen(LiveSignal::class, static function (LiveSignal $signal): void {
            rescue(static function () use ($signal): void {
                app(LiveChannels::class)->recordChange($signal);
                BroadcastLiveSignal::dispatch($signal);
            });
        });
        Event::listen([RoleAttachedEvent::class, RoleDetachedEvent::class], RecordRoleChange::class.'@__invoke');
        Event::listen([BackupHasFailed::class, BackupWasSuccessful::class], RecordBackupOutcome::class.'@__invoke');
        Event::listen(MasterSupervisorLooped::class, PublishWorkerMemory::class.'@__invoke');
        Event::listen(MessageSent::class, static function (): void {
            if (! MailDeliveryMode::logOnly()) {
                app(MailTransportRecord::class)->recordSuccess(CarbonImmutable::now('UTC'));
            }
        });
        Event::listen(ConversionHasBeenCompletedEvent::class, StoreAvatarMetadata::class.'@__invoke');
        Event::listen(OutboxRecorded::class, function (OutboxRecorded $recorded): void {
            $event = OutboxEvent::query()->find($recorded->eventId);
            // A site can be deleted in the recording transaction; its wake-up outlives the cascaded event.
            if ($event !== null && app(NotificationEventTypes::class)->key($event) !== null) {
                DispatchNotifications::dispatch()->afterCommit();
            }
        });
        RateLimiter::for('api-entry', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('api', static function (Request $request): array {
            $user = $request->user();
            if (! $user instanceof User) {
                throw new \LogicException('The API limiter requires an authenticated user.');
            }

            return [Limit::perMinute(60)->by('user:'.$user->id),
                Limit::perMinute(60)->by('token:'.$user->currentAccessToken()->id)];
        });
        RateLimiter::for('cron-pings', static fn (Request $request): array => [
            Limit::perMinute(300)->by('ip:'.($request->ip() ?? 'unknown')),
            Limit::perMinute(120)->by('uuid:'.strtolower((string) $request->route('uuid'))),
        ]);
        RateLimiter::for('site-probe', fn (Request $request): Limit => Limit::perMinute(6)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('backup-now', fn (Request $request): Limit => Limit::perMinutes(5, 1)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        $canonicalId = static function (mixed $value): string {
            if ($value instanceof NotificationDestination || $value instanceof Invitation) {
                return (string) $value->id;
            }
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                $trimmed = ltrim($value, '0');

                return $trimmed === '' ? '0' : $trimmed;
            }

            return 'unknown';
        };
        RateLimiter::for('notification-test', static function (Request $request) use ($canonicalId): array {
            $id = $canonicalId($request->route('channel'));
            $user = $request->user();
            $identity = $user instanceof User ? $user->id : ($request->ip() ?? 'unknown');

            return [
                Limit::perMinute(12)->by('user:'.$identity),
                Limit::perMinute(6)->by('channel:'.$identity.'|'.$id),
            ];
        });
        RateLimiter::for('invitation-resend', static function (Request $request) use ($canonicalId): array {
            $invitationId = $canonicalId($request->route('invitation'));
            $user = $request->user();
            $actor = $user instanceof User ? $user->id : ($request->ip() ?? 'unknown');

            return [
                Limit::perMinute(10)->by('actor:'.$actor),
                Limit::perMinutes(5, 3)->by('invitation:'.$invitationId),
            ];
        });
        RateLimiter::for('profile-delete', static function (Request $request): array {
            $user = $request->user();
            $actor = $user instanceof User ? (string) $user->id : 'guest';
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(5)->by('user-ip:'.$actor.'|'.$ip),
                Limit::perMinute(10)->by('user:'.$actor),
            ];
        });
        Inertia::handleExceptionsUsing(ErrorResponses::respond(...));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Morph columns store these aliases, never class names, so stored data survives class renames.
        // Keep in sync with the 2026_10_16_150000_replace_class_names_with_morph_aliases migration.
        Relation::enforceMorphMap([
            'user' => User::class,
            'site' => Site::class,
            'client' => Client::class,
            'check' => Check::class,
            'check_snooze' => CheckSnooze::class,
            'incident' => Incident::class,
            'invitation' => Invitation::class,
            'maintenance_period' => MaintenancePeriod::class,
            'recurring_maintenance_period' => RecurringMaintenancePeriod::class,
            'maintenance_site_exclusion' => MaintenanceSiteExclusion::class,
            'notification_destination' => NotificationDestination::class,
        ]);

        $timeout = config('database.statement_timeouts.web');
        if ($this->app->runningInConsole()) {
            $background = in_array(config('database.role'), ['worker', 'dispatcher', 'scheduler', 'all-in-one-worker'], true);
            $command = new ArgvInput()->getFirstArgument();
            $runtime = in_array($command, ['horizon', 'horizon:supervisor', 'horizon:work', 'queue:work', 'heptapod:dispatch', 'schedule:run', 'schedule:work'], true);
            // Scheduled commands run as their own artisan subprocess; the scheduler hands them this marker through Laravel's context.
            if (in_array($command, ['schedule:run', 'schedule:work'], true)) {
                Context::addHidden(self::ScheduledCommandContextKey, true);
            }
            $timeout = $background && ($runtime || Context::hasHidden(self::ScheduledCommandContextKey)) ? config('database.statement_timeouts.background') : 0;
            $testTimeout = config('database.statement_timeouts.testing');
            if ($testTimeout !== null && $testTimeout !== '') {
                $timeout = $testTimeout;
            }
        }
        // Select at boot, not config-cache generation: administrative CLI stays unbounded in every role.
        config(['database.connections.pgsql.server_options.statement_timeout' => $timeout]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
