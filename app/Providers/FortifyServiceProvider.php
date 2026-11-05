<?php

namespace Heptapod\Providers;

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
