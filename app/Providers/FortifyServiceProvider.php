<?php

namespace Heptapod\Providers;

use Database\Seeders\DatabaseSeeder;
use Heptapod\Actions\Fortify\ResetUserPassword;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Http\Requests\ActiveTwoFactorLoginRequest;
use Heptapod\Http\Responses\GenericPasswordResetLinkResponse;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(TwoFactorLoginRequest::class, ActiveTwoFactorLoginRequest::class);
        Event::listen(TwoFactorAuthenticationChallenged::class, static function (TwoFactorAuthenticationChallenged $event): void {
            if (! $event->user instanceof User) {
                throw new \LogicException('A two-factor challenge requires an account.');
            }
            request()->session()->put(ActiveTwoFactorLoginRequest::CredentialsKey, ActiveTwoFactorLoginRequest::credentialsFingerprint($event->user));
        });
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(
            FailedPasswordResetResponseContract::class,
            static fn (): FailedPasswordResetResponse => new FailedPasswordResetResponse(PasswordBroker::INVALID_TOKEN),
        );
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        Passkeys::authorizeLoginUsing(static fn (Request $request, PasskeyUser $user, Passkey $passkey): bool => $user instanceof User && $user->disabled_at === null);
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        VerifyEmail::toMailUsing(static function (mixed $user, string $url): Mailable {
            if (! $user instanceof User) {
                throw new \LogicException('Email verification requires an account recipient.');
            }
            $model = app(AccountMailFactory::class)->verification($user, $url);

            return app(MailRenderer::class)->mailable($model)->to($user->getEmailForVerification());
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'instance' => app(InstanceSettings::class)->name,
            'host' => $request->getHttpHost(),
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            ...$this->devLoginProps($request),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'instance' => app(InstanceSettings::class)->name,
            'host' => $request->getHttpHost(),
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'instance' => app(InstanceSettings::class)->name,
            'host' => $request->getHttpHost(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn (Request $request) => Inertia::render('auth/confirm-password', [
            'instance' => app(InstanceSettings::class)->name,
            'host' => $request->getHttpHost(),
        ]));
    }

    /**
     * The local demo sign-in on the login page. The login-link package is a dev dependency, absent from production images;
     * its route exists only when the package is installed, so production code never references a package class.
     *
     * @return array{devLogin?: array{action: string, email: string, redirectUrl: ?string}}
     */
    private function devLoginProps(Request $request): array
    {
        if (! app()->isLocal() || ! Route::has('loginLinkLogin') || ! in_array($request->getHost(), config()->array('login-link.allowed_hosts'), true)) {
            return [];
        }

        return ['devLogin' => [
            'action' => route('loginLinkLogin', absolute: false),
            'email' => DatabaseSeeder::DEMO_EMAIL,
            'redirectUrl' => self::devLoginRedirectUrl($request),
        ]];
    }

    /**
     * The intended URL for a dev login, only when it is on this request's origin; null keeps the login-link default.
     */
    public static function devLoginRedirectUrl(Request $request): ?string
    {
        $intended = $request->session()->get('url.intended');

        return is_string($intended) && str_starts_with($intended, $request->getSchemeAndHttpHost().'/') ? $intended : null;
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', static function (Request $request): Limit {
            $rawEmail = $request->input(Fortify::username());
            $email = is_string($rawEmail) ? Str::transliterate(Str::lower($rawEmail)) : '';
            $throttleKey = $email.'|'.($request->ip() ?? 'unknown');

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Pre-session source cap: 60 full passkey sign-ins/min (options + assertion) behind one NAT IP.
        RateLimiter::for('passkeys-entry', static fn (Request $request): Limit => Limit::perMinute(120)->by('ip:'.($request->ip() ?? 'unknown')));

        RateLimiter::for('passkeys', static function (Request $request): Limit|array {
            $limits = [];
            $user = $request->user();
            if ($user instanceof User) {
                $limits[] = Limit::perMinute(15)->by('user:'.$user->id);
            }
            $credentialId = $request->input('credential.id');
            if (is_string($credentialId) && $credentialId !== '') {
                $limits[] = Limit::perMinute(10)->by('cred:'.$credentialId);
            }

            return $limits === [] ? Limit::none() : $limits;
        });

        RateLimiter::for('password-confirm', static function (Request $request): array {
            $user = $request->user();
            $actor = $user instanceof User ? (string) $user->id : 'guest';
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(5)->by('user-ip:'.$actor.'|'.$ip),
                Limit::perMinute(10)->by('user:'.$actor),
            ];
        });

        // Budget: up to 30 distinct addresses/min behind a shared NAT IP, 3/min per normalized email.
        RateLimiter::for('password-reset-link', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $limits = [Limit::perMinute(30)->by('ip:'.$ip)];
            $rawEmail = $request->input(Fortify::email());
            if (is_string($rawEmail) && $rawEmail !== '') {
                $email = Str::transliterate(Str::lower($rawEmail));
                $limits[] = Limit::perMinute(3)->by('email:'.$email);
            }

            return $limits;
        });

        // Budget: up to 30 reset attempts/min behind a shared NAT IP, 5/min per email+IP, 10/min per email globally.
        RateLimiter::for('password-reset', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $limits = [Limit::perMinute(30)->by('ip:'.$ip)];
            $rawEmail = $request->input(Fortify::email());
            if (is_string($rawEmail) && $rawEmail !== '') {
                $email = Str::transliterate(Str::lower($rawEmail));
                $limits[] = Limit::perMinute(5)->by('email-ip:'.$email.'|'.$ip);
                $limits[] = Limit::perMinute(10)->by('email:'.$email);
            }

            return $limits;
        });
    }
}
