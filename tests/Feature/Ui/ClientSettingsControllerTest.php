<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('lets owners create clients, edit branding, and rejects unsafe uploads', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->get('/clients/create')->assertInertia(fn (Assert $page): Assert => $page->component('clients/settings')->where('client', null));
    $this->post('/clients', [
        'name' => 'Example', 'short_name' => 'EX', 'contact_name' => 'Ada',
        'locale' => 'de', 'timezone' => 'Europe/Berlin', 'accent_color' => '#3B5068',
        'logo' => UploadedFile::fake()->image('logo.png', 320, 320),
    ])->assertRedirect();
    $client = Client::query()->where('name', 'Example')->sole();
    expect($client->slug)->toStartWith('client-');
    $logo = $client->getFirstMedia('logo') ?? throw new LogicException('The uploaded logo must exist.');
    expect($logo->mime_type)->toBe('image/png');
    expect($logo->file_name)->toEndWith('.png')->not->toBe('logo.png');
    expect(Activity::query()->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)
        ->where('event', 'client.avatar.updated')->where('causer_id', $owner->id)->where('properties->source', 'uploaded')->exists())->toBeTrue();
    $logoUrl = '/clients/'.$client->id.'/logo/'.$logo->getCustomProperty('avatar_hash');
    $assigned = User::factory()->create(['name' => 'Assigned member']);
    $assigned->assignRole('Member');
    $assigned->clients()->attach($client);
    $unassigned = User::factory()->create(['name' => 'Hidden member']);
    $unassigned->assignRole('Member');
    $settings = $this->get('/clients/'.$client->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page->component('clients/settings')
        ->where('client.short_name', 'EX')->where('client.accent_color', '#3B5068')->where('client.locale', 'de')->where('client.timezone', 'Europe/Berlin')->where('client.avatar.url', $logoUrl)
        ->has('members', 2));
    expect(collect($settings->inertiaProps()['members'])->pluck('name')->all())->toContain('Assigned member')->not->toContain('Hidden member');
    $this->post('/clients/'.$client->id, ['_method' => 'PATCH', 'name' => 'Renamed', 'accent_color' => '#2F5D4A'])->assertRedirect();
    expect($client->refresh()->name)->toBe('Renamed');
    $this->patch('/clients/'.$client->id, ['accent_color' => '#FFFFFF'])->assertSessionHasErrors('accent_color');
    $this->patch('/clients/'.$client->id, ['logo' => UploadedFile::fake()->createWithContent('payload.svg', '<svg onload="alert(1)"/>')])->assertSessionHasErrors('logo');
    $this->patch('/clients/'.$client->id, ['logo' => UploadedFile::fake()->image('oversized.png', 4097, 20)])->assertSessionHasErrors('logo');
    expect($client->fresh()?->getFirstMedia('logo')?->mime_type)->toBe('image/png');
    // The settings Save: the identity fields and the avatar draft in one spoofed multipart PATCH.
    $this->post('/clients/'.$client->id, [
        '_method' => 'PATCH', 'name' => 'Renamed with logo', 'short_name' => 'EX', 'contact_name' => 'Ada',
        'locale' => 'de', 'timezone' => 'Europe/Berlin', 'logo' => UploadedFile::fake()->image('replacement.png', 320, 320),
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($client->refresh()->name)->toBe('Renamed with logo');
    $replacement = $client->getFirstMedia('logo') ?? throw new LogicException('The replacement logo must exist.');
    expect($replacement->id)->not->toBe($logo->id);
    expect(Activity::query()->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)
        ->where('event', 'client.avatar.updated')->where('causer_id', $owner->id)->orderBy('id')->pluck('properties')->map(fn ($properties): string => $properties['source'])->all())->toBe(['uploaded', 'uploaded']);
    $this->patch('/clients/'.$client->id, ['remove_logo' => true])->assertRedirect();
    expect($client->fresh()?->getFirstMedia('logo'))->toBeNull();
    expect(Activity::query()->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)
        ->where('event', 'client.avatar.updated')->where('causer_id', $owner->id)->where('properties->source', 'automatic')->exists())->toBeTrue();
});

it('preserves stored branding language time and assignments on an identity-only web save', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->post('/clients', ['name' => 'Preserved settings', 'locale' => 'de', 'timezone' => 'Europe/Berlin',
        'accent_color' => '#3B5068', 'logo' => UploadedFile::fake()->image('preserved.png', 320, 320)])->assertRedirect();
    $client = Client::query()->where('name', 'Preserved settings')->sole();
    $logoId = $client->getFirstMedia('logo')->id;
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);

    $this->patch('/clients/'.$client->id, ['name' => 'Identity only', 'short_name' => 'IO',
        'contact_name' => 'New contact'])->assertRedirect();
    $client->refresh();
    expect($client->name)->toBe('Identity only')->and($client->locale)->toBe('de')->and($client->timezone)->toBe('Europe/Berlin')
        ->and($client->accent_color)->toBe('#3B5068')->and($client->getFirstMedia('logo')->id)->toBe($logoId);
    expect($member->clients()->whereKey($client->id)->exists())->toBeTrue();
    $this->actingAs($member)->get('/clients/'.$client->id)->assertOk();
    $this->get('/clients/'.$client->id.'/settings')->assertNotFound();
});

it('rejects image content disguised with an HTML filename', function (): void {
    $this->withoutVite();
    Storage::fake('public');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $png = UploadedFile::fake()->image('safe.png', 320, 320);

    $this->actingAs($owner)->post('/clients', [
        'name' => 'Disguised logo',
        'logo' => new UploadedFile($png->getRealPath(), 'x.html', test: true),
    ])->assertSessionHasErrors('logo');
    $this->assertDatabaseMissing('clients', ['name' => 'Disguised logo']);
});

it('serves logos only to users who can view their client', function (): void {
    $this->withoutVite();
    Storage::fake('public');
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $allowed = Client::factory()->create();
    $hidden = Client::factory()->create();
    foreach ([$allowed, $hidden] as $client) {
        $this->actingAs($owner)->patch('/clients/'.$client->id, [
            'logo' => UploadedFile::fake()->image('brand.png', 320, 320),
        ])->assertRedirect();
    }
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($allowed);

    $hiddenLogo = $hidden->fresh()->getFirstMedia('logo');
    $logoUrl = '/clients/'.$allowed->id.'/logo/'.$allowed->fresh()->getFirstMedia('logo')->getCustomProperty('avatar_hash');
    $this->actingAs($member)->get('/clients/'.$hidden->id.'/logo/'.$hiddenLogo->getCustomProperty('avatar_hash'))->assertNotFound();
    $response = $this->get($logoUrl)->assertOk()
        ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('immutable'))->toBeTrue()
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and(str_starts_with($response->streamedContent(), "\x89PNG\r\n\x1a\n"))->toBeTrue();
    $media = $allowed->fresh()->getFirstMedia('logo') ?? throw new LogicException('The uploaded logo must exist.');
    $media->update(['mime_type' => 'text/html']);
    $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/clients/'.$allowed->id.'/logo/'.str_repeat('0', 64))->assertNotFound();
    expect($media->disk)->toBe('local');
    Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    $this->actingAs(User::factory()->create())->get($logoUrl)->assertForbidden();
    $this->actingAs($member)->get('/clients/'.$allowed->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('client.avatar.url', $logoUrl));
});

it('archives clients without deleting history and protects the Internal client', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $check = Check::factory()->for($site)->create();
    $internal = Client::query()->where('is_internal', true)->sole();
    Site::factory()->for($internal)->create();
    $this->actingAs($owner)->post('/clients/'.$client->id.'/archive')->assertRedirect('/clients');
    expect($client->fresh()?->archived_at)->not->toBeNull();
    expect($site->fresh()?->paused_at)->not->toBeNull();
    expect(Check::query()->find($check->id))->not->toBeNull();
    $active = $this->get('/clients')->assertOk()->inertiaProps()['clients'];
    expect(collect($active)->pluck('client.id')->all())->toContain($internal->id)->not->toContain($client->id);
    $archived = $this->get('/clients?filter[state]=archived')->assertOk()->inertiaProps()['clients'];
    $archivedClient = collect($archived)->firstWhere('client.id', $client->id);
    expect($archivedClient['summary']['sites'])->toBe(1);
    $sites = $this->get('/sites', ['X-Inertia-Partial-Component' => 'sites/index', 'X-Inertia-Partial-Data' => 'sites'])->assertOk()->inertiaProps()['sites'];
    expect(collect($sites)->pluck('id')->all())->not->toContain($site->id);
    $this->get('/clients/'.$client->id)->assertInertia(fn (Assert $page): Assert => $page->has('sites', 1));
    $this->post('/sites', ['client_id' => $client->id])->assertNotFound();
    $this->post('/sites/'.$site->id.'/resume')->assertForbidden();
    expect($site->fresh()?->paused_at)->not->toBeNull();
    $this->post('/clients/'.$internal->id.'/archive')->assertForbidden();
    expect($internal->fresh()?->archived_at)->toBeNull();
});

it('denies client management to members and roleless users', function (): void {
    $client = Client::factory()->create();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get('/clients/create')->assertForbidden();
    $this->post('/clients', ['name' => 'Forbidden'])->assertForbidden();
    $this->get('/clients/'.$client->id.'/settings')->assertNotFound();
    $this->patch('/clients/'.$client->id, ['name' => 'Forbidden'])->assertNotFound();
    $this->post('/clients/'.$client->id.'/archive')->assertNotFound();
    $this->actingAs(User::factory()->create())->post('/clients', ['name' => 'Forbidden'])->assertForbidden();
});
