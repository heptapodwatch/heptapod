<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\NormaliseSiteUrl;
use Heptapod\Actions\SiteUrlDuplicates;
use Heptapod\Checks\SiteProbe;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class SiteProbeController extends Controller
{
    public function __invoke(Request $request, SiteProbe $probe, NormaliseSiteUrl $normalise, SiteUrlDuplicates $duplicates): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);
        /** @var array{url: string, site_id?: int} $data */
        $data = Validator::make($request->all(), ['url' => ['required', 'string', 'max:2048'], 'site_id' => ['sometimes', 'integer', 'min:1']])->validate();
        $site = isset($data['site_id']) ? Site::query()->findOrFail($data['site_id']) : null;
        if ($site !== null) {
            Gate::authorize('update', $site);
        }
        $url = $normalise($data['url']);
        $result = $probe($url);
        $duplicate = $site !== null && $site->url === $data['url'] ? null : $duplicates->find($url, $site?->id);
        if ($duplicate !== null) {
            $result->duplicate_message = $duplicates->message($duplicate);
            $result->duplicate_site_id = $duplicates->visible($duplicate) ? $duplicate->id : null;
        } else {
            $warning = $duplicates->warning($url, $site?->id);
            $result->warning_site_name = $warning?->displayName();
            $result->warning_site_id = $warning?->id;
        }

        return response()->json($result->toArray());
    }
}
