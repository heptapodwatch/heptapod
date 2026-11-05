<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Checks\SiteProbe;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class SiteProbeController extends Controller
{
    public function __invoke(Request $request, SiteProbe $probe): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);
        /** @var array{url: string} $data */
        $data = Validator::make($request->all(), ['url' => ['required', 'string', 'max:2048', 'url:http,https',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                    $fail(__('api.url_credentials_unsupported'));
                }
            },
        ]])->validate();

        return response()->json($probe($data['url'])->toArray());
    }
}
