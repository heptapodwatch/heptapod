<?php

declare(strict_types=1);

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log']);
    Storage::fake('local');
});

function emailSettingsImage(int $width, int $height, string $type = 'png'): string
{
    $image = imagecreatetruecolor($width, $height) ?: throw new LogicException('GD could not create an image.');
    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image);

    return (string) ob_get_clean();
}

/** @return array<string, mixed> */
function emailSettingsForm(array $overrides = []): array
{
    return ['_method' => 'patch', 'mail_design' => 'branded', 'sender_name' => 'Acme Monitoring', 'accent' => '#1D4ED8',
        'footer' => 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin', ...$overrides];
}

it('saves every email appearance field and serves the saved logos to the owner only', function (): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->post(route('settings.email.update'), emailSettingsForm([
        'mail_design' => 'plain',
        'logo' => UploadedFile::fake()->createWithContent('logo.png', emailSettingsImage(480, 48)),
        // The file name says PNG; the content (a JPEG) decides the stored type.
        'logo_dark' => UploadedFile::fake()->createWithContent('dark.png', emailSettingsImage(96, 48, 'jpeg')),
    ]))->assertRedirect('/settings/email')->assertSessionHasNoErrors();

    $settings = app(InstanceSettings::class);
    expect($settings->mail_design)->toBe(MailDesign::Plain)
        ->and($settings->mail_sender_name)->toBe('Acme Monitoring')
        ->and($settings->mail_accent)->toBe('#1d4ed8')
        ->and($settings->mail_footer)->toBe('Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin')
        // 10:1 hits the 200 px cap and keeps its ratio; 2:1 renders at the full 24 px height.
        ->and([$settings->mail_logo_width, $settings->mail_logo_height])->toBe([200, 20])
        ->and([$settings->mail_logo_dark_width, $settings->mail_logo_dark_height])->toBe([48, 24])
        ->and($settings->mail_logo_path)->toEndWith('.png')
        ->and($settings->mail_logo_dark_path)->toEndWith('.jpg');
    Storage::disk('local')->assertExists([$settings->mail_logo_path, $settings->mail_logo_dark_path]);

    $page = $this->get('/settings/email')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('settings/email')
        ->where('instancePage.mail_design', 'plain')->where('emailPage.sender_name', 'Acme Monitoring')->where('emailPage.accent', '#1d4ed8')
        ->where('emailPage.footer', 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin'));
    $logoUrl = $page->viewData('page')['props']['emailPage']['logo_url'];
    $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get($page->viewData('page')['props']['emailPage']['logo_dark_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $admin = User::factory()->create()->assignRole('Admin');
    $this->actingAs($admin)->get($logoUrl)->assertForbidden();
});

it('removes the logo together with its dark-mode logo and deletes both files', function (): void {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->post(route('settings.email.update'), emailSettingsForm([
        'logo' => UploadedFile::fake()->createWithContent('logo.png', emailSettingsImage(48, 48)),
        'logo_dark' => UploadedFile::fake()->createWithContent('dark.png', emailSettingsImage(48, 24)),
    ]))->assertSessionHasNoErrors();
    $settings = app(InstanceSettings::class);
    $files = [$settings->mail_logo_path, $settings->mail_logo_dark_path];

    $this->post(route('settings.email.update'), emailSettingsForm(['remove_logo' => '1', 'sender_name' => '', 'accent' => '', 'footer' => '']))
        ->assertRedirect('/settings/email')->assertSessionHasNoErrors();

    $settings = app(InstanceSettings::class);
    expect([$settings->mail_logo_path, $settings->mail_logo_dark_path, $settings->mail_sender_name, $settings->mail_accent, $settings->mail_footer])
        ->toBe([null, null, null, null, null]);
    Storage::disk('local')->assertMissing($files);
});

it('refuses each invalid field with a message at that field and saves nothing', function (array $input, string $field, string $message): void {
    $owner = User::factory()->owner()->create();
    $before = app(InstanceSettings::class)->toArray();

    $this->actingAs($owner)->from('/settings/email')->post(route('settings.email.update'), emailSettingsForm(['mail_design' => 'plain', ...$input]))
        ->assertRedirect('/settings/email')->assertSessionHasErrors([$field => $message]);

    expect(app(InstanceSettings::class)->toArray())->toBe($before);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'sender name too long' => fn (): array => [['sender_name' => str_repeat('a', 101)], 'sender_name', __('mail_branding.sender.too_long', ['max' => 100])],
    'sender name with a header injection' => fn (): array => [['sender_name' => "Acme\r\nBcc: victim@example.test"], 'sender_name', __('mail_branding.sender.one_line')],
    'accent not a hex colour' => fn (): array => [['accent' => 'blue'], 'accent', __('mail_branding.accent.format')],
    'accent short hex' => fn (): array => [['accent' => '#12345'], 'accent', __('mail_branding.accent.format')],
    'accent unreadable under white text' => fn (): array => [['accent' => '#f5c400'], 'accent', __('mail_branding.accent.unreadable', ['ratio' => '1.6'])],
    'footer too long' => fn (): array => [['footer' => str_repeat('a', 161)], 'footer', __('mail_branding.footer.too_long', ['max' => 160])],
    'footer with HTML' => fn (): array => [['footer' => 'Acme <b>GmbH</b>'], 'footer', __('mail_branding.footer.plain')],
    'footer with a line break' => fn (): array => [['footer' => "Acme GmbH\nBerlin"], 'footer', __('mail_branding.footer.plain')],
    'logo larger than 512 KB' => fn (): array => [['logo' => UploadedFile::fake()->create('logo.png', 600, 'image/png')], 'logo', __('mail_branding.logo.too_large')],
    'logo text named .png' => fn (): array => [['logo' => UploadedFile::fake()->createWithContent('logo.png', 'not an image')], 'logo', __('mail_branding.logo.unreadable')],
    'logo GIF named .png' => fn (): array => [['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('R0lGODlhAQABAAAAACw=', true) ?: '')], 'logo', __('mail_branding.logo.unsupported')],
    'logo PNG header that does not decode' => fn (): array => [['logo' => UploadedFile::fake()->createWithContent('logo.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64))], 'logo', __('mail_branding.logo.unreadable')],
    'dark-mode logo without a logo' => fn (): array => [['logo_dark' => UploadedFile::fake()->createWithContent('dark.png', emailSettingsImage(48, 24))], 'logo_dark', __('mail_branding.logo_dark.needs_logo')],
]);

it('keeps Settings → Email, its preview, saving and logos Owner-only', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);

    $this->actingAs($user)->get('/settings/email')->assertForbidden();
    $this->post(route('settings.email.update'), emailSettingsForm())->assertForbidden();
    $this->getJson(route('settings.email.preview', ['kind' => 'alert', 'design' => 'branded']))->assertForbidden();
    expect(app(InstanceSettings::class)->mail_sender_name)->toBeNull();
})->with(['Admin', 'Member']);

it('previews the unsaved draft with the real templates', function (): void {
    $owner = User::factory()->owner()->create(['locale' => 'en']);
    $this->actingAs($owner);

    $alert = $this->getJson(route('settings.email.preview', ['kind' => 'alert', 'design' => 'branded', 'accent' => '#1d4ed8',
        'footer' => 'Acme Web Studio GmbH', 'logo_width' => 120, 'logo_height' => 24]))->assertOk()->json();
    // A draft logo is the page's own image: the preview names its place and size, never a lockup or another file.
    expect($alert['subject'])->toBe(__('mail_branding.sample.subject'))
        ->and($alert['html'])->toContain('background:#1d4ed8', 'Acme Web Studio GmbH', 'src="cid:brand-logo@heptapod" width="120" height="24"',
            '.logo-plate{background:#ffffff')->not->toContain('data:image/png', 'height:3px');

    $reset = $this->getJson(route('settings.email.preview', ['kind' => 'reset', 'design' => 'plain', 'footer' => 'Acme Web Studio GmbH']))->assertOk()->json();
    // Account mail is always Branded, so the Plain design does not apply to it.
    expect($reset['html'])->toContain('class="btn-primary"', 'Acme Web Studio GmbH', 'data:image/png;base64,');

    $plain = $this->getJson(route('settings.email.preview', ['kind' => 'alert', 'design' => 'plain', 'footer' => 'Acme Web Studio GmbH']))->assertOk()->json();
    expect($plain['html'])->toContain('Acme Web Studio GmbH', 'prefers-color-scheme:dark')->not->toContain('<img');

    $this->getJson(route('settings.email.preview', ['kind' => 'alert', 'design' => 'branded', 'accent' => '#f5c400']))
        ->assertUnprocessable()->assertJsonValidationErrors('accent');
    $this->getJson(route('settings.email.preview', ['kind' => 'alert', 'design' => 'branded', 'logo_dark_width' => 48, 'logo_dark_height' => 24]))
        ->assertUnprocessable()->assertJsonValidationErrors('logo_dark_width');
});
