<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SnoozeCheck;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Checks')]
final class SnoozeCheckController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, Check $check, SnoozeCheck $snooze, AuthorizeSiteWrite $authorize): CheckResource
    {
        Gate::authorize('snooze', $check);
        $absolute = $request->has('snooze_until');
        Validator::make($request->all(), $absolute ? ['snooze_until' => ['required', 'string', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/']] : ['minutes' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
            if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]{0,5}$/D', (string) $value) !== 1 || (int) $value > 525600) {
                $fail(__('api.invalid_minutes'));
            }
        }]])->validate();
        // The minutes form stays for compatibility; snooze_until is the retry-safe form.
        $until = $absolute ? CarbonImmutable::parse($request->string('snooze_until')->toString())->utc() : CarbonImmutable::now('UTC')->addMinutes($request->integer('minutes'));
        // The resource reads the site loaded under the lock: a site deleted after the commit must not fail the response.
        $authorize($user, 'snooze', static function () use ($snooze, $check, $user, $until): void {
            $snooze($check, $user, $until);
            $check->load(['snoozes', 'site.checks']);
        }, $check);

        return new CheckResource($check);
    }
}
