<?php

declare(strict_types=1);

use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

it('refuses upcoming maintenance to a user without a monitoring role like every other upcoming type', function (): void {
    $this->freezeTime();
    $window = MaintenancePeriod::factory()->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    // ApiTestCase validates against the pinned Oh Dear spec, which has no upcoming endpoint; call the kernel directly.
    $upcoming = static function (User $user, string $query): array {
        app('auth')->forgetGuards();
        $response = app(HttpKernel::class)->handle(Request::create('/api/upcoming'.$query, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$user->createToken('upcoming', ['upcoming:read'])->plainTextToken, 'HTTP_ACCEPT' => 'application/json']));

        return [$response->getStatusCode(), array_column(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'] ?? [], 'key')];
    };

    expect($upcoming(User::factory()->owner()->create(), '?type=maintenance'))->toBe([200, ['maintenance:'.$window->id]]);
    $roleless = User::factory()->create();
    expect($upcoming($roleless, ''))->toBe([403, []])
        ->and($upcoming($roleless, '?type=maintenance'))->toBe([403, []]);
});
