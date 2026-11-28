<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\Findings\FindingsView;
use Heptapod\Enums\DailyMetricRange;
use Heptapod\Enums\ResponseTimeRange;
use Heptapod\Models\User;
use Heptapod\Queries\SiteDetailReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CheckTabController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, int $site, int $check, SiteDetailReader $reader): Response|RedirectResponse
    {
        $requested = $request->query('response_range', ResponseTimeRange::Hours24->value);
        $range = is_string($requested) ? ResponseTimeRange::tryFrom($requested) : null;
        // An edited or outdated link: open the same check on its default range and say so.
        if ($range === null) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('history.unknown_range')]);

            return to_route('sites.checks.show', ['site' => $site, 'check' => $check]);
        }

        $requestedMetrics = $request->query('metric_range', DailyMetricRange::Days30->value);
        $metricRange = is_string($requestedMetrics) ? DailyMetricRange::tryFrom($requestedMetrics) : null;
        if ($metricRange === null) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('history.unknown_daily_range')]);

            return to_route('sites.checks.show', ['site' => $site, 'check' => $check]);
        }

        $view = $request->query('findings_view') === 'ignored' ? FindingsView::Ignored : FindingsView::Current;

        return Inertia::render('sites/show', $reader->checkPage($user, $site, $check, $range, $metricRange, $view));
    }
}
