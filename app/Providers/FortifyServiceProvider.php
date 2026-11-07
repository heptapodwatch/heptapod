<?php

namespace Heptapod\Providers;

use Database\Seeders\DatabaseSeeder;
use Heptapod\Actions\Fortify\ResetUserPassword;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Http\Requests\ActiveTwoFactorLoginRequest;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
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

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->filled('credential.id') ? $request->string('credential.id')->toString() : $request->session()->getId()).'|'.$request->ip(),
        ));
    }
}
