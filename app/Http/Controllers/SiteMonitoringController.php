<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\PauseSite;
use Heptapod\Actions\ResumeSite;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class SiteMonitoringController extends Controller
{
    public function store(Request $request, Site $site, PauseSite $pause, InstanceSettings $settings, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        Gate::authorize('update', $site);
        /** @var array{until?: 'manual'|'hour'|'tomorrow'} $data */
        $data = Validator::make($request->all(), ['until' => ['sometimes', 'in:manual,hour,tomorrow']])->validate();
        $now = CarbonImmutable::now($settings->timezone);
        $resumeAt = match ($data['until'] ?? 'manual') {
            'manual' => null,
            'hour' => $now->addHour()->utc(),
            'tomorrow' => $now->addDay()->setTime(8, 0)->utc(),
        };
        // Decided again under the shared access lock, with the actor as the account is now.
        $authorize($this->user($request), 'update', static fn () => $pause($site, $resumeAt), $site);

        return back();
    }

    public function destroy(Request $request, Site $site, ResumeSite $resume, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        Gate::authorize('update', $site);
        $authorize($this->user($request), 'update', static fn () => $resume($site), $site);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
