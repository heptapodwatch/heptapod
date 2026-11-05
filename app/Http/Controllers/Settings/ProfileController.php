<?php

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\DeleteProfile;
use Heptapod\Actions\UpdateProfile;
use Heptapod\Data\Ui\ProfilePageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Settings\ProfileDeleteRequest;
use Heptapod\Http\Requests\Settings\ProfileUpdateRequest;
use Heptapod\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var string|null $status */
        $status = $request->session()->get('status');

        return Inertia::render('settings/profile', new ProfilePageData(
            mustVerifyEmail: $request->user() instanceof MustVerifyEmail,
            status: $status,
            timezones: \DateTimeZone::listIdentifiers(),
        ));
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfile $update): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $update($user, $request->validated());

        if ($request->has('locale')) {
            app()->setLocale($request->string('locale')->toString());
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        $response = to_route('profile.edit');
        if ($request->has('locale')) {
            $response->withCookie(cookie('locale', $request->string('locale')->toString(), 525600, httpOnly: false));
        }

        return $response;
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteProfile $delete): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $delete($user);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
