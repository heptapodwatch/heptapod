<?php

declare(strict_types=1);

use Heptapod\Actions\CreateApiToken;
use Heptapod\Actions\CreateEmailDestination;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/** @param array<string, string> $body */
function localeApi(string $method, string $path, string $token, array $body = []): TestResponse
{
    // These routes aren't in the pinned Oh Dear spec used by ApiTestCase.
    app('auth')->forgetGuards();
    $request = Request::create($path, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

    return TestResponse::fromBaseResponse(app(Kernel::class)->handle($request));
}

beforeEach(function (): void {
    config(['app.supported_locales' => [...config()->array('app.supported_locales'), 'es-ES']]);
});

it('keeps supported locale codes in the notification channel storage format', function (): void {
    foreach (config()->array('app.supported_locales') as $locale) {
        expect($locale)->toMatch('/^[a-z]{2}(-[A-Z]{2})?$/D');
    }
});

it('persists a configured profile language and shares its endonym after reload', function (): void {
    $user = User::factory()->owner()->create();
    Lang::addJsonPath(base_path('tests/Fixtures/locales'));

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'es-ES'])
        ->assertSessionHasNoErrors()->assertPlainCookie('locale', 'es-ES');

    expect($user->refresh()->locale)->toBe('es-ES');
    $this->withoutVite()->get(route('profile.edit'))->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('locale', 'es-ES')
        ->where('supportedLocales', function (Collection $locales): bool {
            $names = $locales->pluck('name', 'code');

            return $names->get('en') === 'English' && $names->get('de') === 'Deutsch' && $names->get('es-ES') === 'español (España)';
        }));
    expect(__('Language'))->toBe('Idioma');
});

it('accepts a configured client language and rejects an unknown one on web and API writes', function (bool $api): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $token = $api ? app(CreateApiToken::class)($owner, 'Locale test', ['clients:write']) : null;
    if ($token === null) {
        $this->actingAs($owner);
    }
    $request = fn (string $method, string $url, array $input): TestResponse => $token === null
        ? $this->json($method, $url, $input) : localeApi($method, $url, $token, $input);
    $collection = $api ? '/api/clients' : route('clients.store');
    $record = $api ? '/api/clients/'.$client->id : route('clients.update', $client);
    $method = $api ? 'PUT' : 'PATCH';

    $request('POST', $collection, ['name' => 'Locale client', 'locale' => 'es-ES'])->assertCreated()->assertJsonPath('locale', 'es-ES');
    $request($method, $record, ['locale' => 'es-ES'])->assertStatus($api ? 200 : 302);
    $request('POST', $collection, ['name' => 'Unknown language', 'locale' => 'zz'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    $request($method, $record, ['locale' => 'zz'])->assertUnprocessable()->assertJsonValidationErrors('locale');

    expect($client->refresh()->locale)->toBe('es-ES');
})->with(['web' => [false], 'api' => [true]]);

it('accepts a configured team language and rejects an unknown one on channel creation and update', function (): void {
    $this->actingAs(User::factory()->owner()->create());
    $channel = NotificationDestination::factory()->create();
    $input = ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'Alerts', 'config' => ['email' => 'alerts@example.test']];

    $id = $this->postJson(route('notification-channels.store'), [...$input, 'locale' => 'es-ES'])->assertCreated()->json('id');
    $this->patchJson(route('notification-channels.update', $channel), ['locale' => 'es-ES'])->assertOk();
    $this->postJson(route('notification-channels.store'), [...$input, 'locale' => 'zz'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    $this->patchJson(route('notification-channels.update', $channel), ['locale' => 'zz'])->assertUnprocessable()->assertJsonValidationErrors('locale');

    expect(NotificationDestination::query()->findOrFail($id)->destinationConfig()->locale)->toBe('es-ES');
    expect($channel->refresh()->locale)->toBe('es-ES');
});

it('accepts a configured locale and rejects an unknown one when provisioning an email destination', function (): void {
    $create = app(CreateEmailDestination::class);
    $input = ['address' => 'alerts@example.test', 'label' => 'Test mail'];

    expect($create([...$input, 'locale' => 'es-ES'])->destinationConfig()->locale)->toBe('es-ES');
    expect(fn (): NotificationDestination => $create([...$input, 'locale' => 'zz']))->toThrow(ValidationException::class);
});

it('accepts a configured headline locale and rejects an unknown one with 422', function (string $path, int $status): void {
    $this->freezeTime();
    $owner = User::factory()->owner()->create();
    $token = app(CreateApiToken::class)($owner, 'Locale test', ['incidents:read', 'activity:read', 'checks:read']);

    localeApi('GET', $path.'?locale=es-ES', $token)->assertStatus($status);
    localeApi('GET', $path.'?locale=zz', $token)->assertUnprocessable()->assertJsonValidationErrors('locale');
})->with([
    'activity' => ['/api/activity', 200],
    'incident list' => ['/api/incidents', 200],
    'incident details' => ['/api/incidents/1', 404],
    'run details' => ['/api/runs/attempt:1', 404],
]);
