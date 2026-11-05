<?php

declare(strict_types=1);

use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

test('passkeys without a creation timestamp omit the added label', function (bool $wasUsed): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Undated key',
        'credential_id' => 'undated-credential',
        'credential' => [],
    ])->forceFill([
        'created_at' => null,
        'last_used_at' => $wasUsed ? now() : null,
    ])->save();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $page = visit('/settings/security')
        ->assertSee('Undated key')
        ->assertDontSee('Added')
        ->assertNoJavaScriptErrors();

    if ($wasUsed) {
        $page->assertSee('Last used');
    } else {
        $page->assertDontSee('Last used');
    }
})->with(['never used' => false, 'previously used' => true]);
