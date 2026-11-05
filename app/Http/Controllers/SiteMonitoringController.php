<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\PauseSite;
use Heptapod\Actions\ResumeSite;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class SiteMonitoringController extends Controller
{
    public function store(Request $request, Site $site, PauseSite $pause, InstanceSettings $settings): RedirectResponse
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
        $pause($site, $resumeAt);

        return back();
    }

    public function destroy(Site $site, ResumeSite $resume): RedirectResponse
    {
        Gate::authorize('update', $site);
        $resume($site);

        return back();
    }
}
