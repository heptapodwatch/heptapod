<?php

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\Fortify\ResetUserPassword;
use Heptapod\Data\Ui\SecurityPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Settings\PasswordUpdateRequest;
use Heptapod\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;

final class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? array_values($user
                    ->passkeys()
                    ->getQuery()
                    ->latest()
                    ->get(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->map(fn (Passkey $passkey): array => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at?->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->all())
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', new SecurityPageData(...$props));
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, ResetUserPassword $reset): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $reset->reset($user, ['password' => $request->string('password')->toString(), 'password_confirmation' => $request->string('password_confirmation')->toString()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
