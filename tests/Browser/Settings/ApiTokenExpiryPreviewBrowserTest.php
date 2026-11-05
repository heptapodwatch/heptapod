<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('takes the token expiry in the account timezone before Create without issuing a token', function (string $locale, string $createLabel, string $help): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['locale' => $locale, 'timezone' => 'Europe/Berlin']);
    $user->assignRole('Owner');
    $this->actingAs($user);

    $page = visit('/settings/api-tokens')->resize(1440, 900)->click($createLabel)->assertSee($help)->assertDontSee('UTC');
    // The field names the account timezone; the submitted UTC instant is covered by resources/js/pages/settings/api-tokens.test.tsx.
    $page->fill('token-expiry', '2030-01-02 03:04')->assertSee('Europe/Berlin')->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'expiry-account-timezone-'.$locale.'-1440');
    expect($user->tokens()->count())->toBe(0);
})->with([
    'English' => ['en', 'Create token', 'Leave empty for no expiry.'],
    'German' => ['de', 'Token erstellen', 'Leer lassen für unbegrenzte Gültigkeit.'],
]);
