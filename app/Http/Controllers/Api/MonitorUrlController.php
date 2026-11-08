<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\MonitorResource;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Monitors')]
final class MonitorUrlController extends Controller
{
    public function __invoke(Request $request): MonitorResource
    {
        // Decode the raw path exactly once: the SDK encodes literal + as %2B and spaces as +.
        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        $url = urldecode(substr($path, strlen('/api/monitors/url/')));
        $site = Site::query()->where('url', $url)->firstOrFail();
        Gate::authorize('view', $site);

        return new MonitorResource($site->load(['checks' => static function (Relation $checks): void {
            $checks->getQuery()->getQuery()->orderBy('id');
        }, 'checks.snoozes', 'tags', 'client']));
    }
}
