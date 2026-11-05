<?php

declare(strict_types=1);

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the actual deletion impact and restricts site settings to authorized operators', function (): void {
    $this->withoutVite();
    $client = Client::factory()->create();
    $hidden = Client::factory()->create();
    $operator = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $operator->assignRole('Admin');
    $operator->clients()->attach($client);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'Review site']);
    $check = Check::factory()->for($site)->create();
    CheckRun::factory()->for($check)->create();
    Incident::factory()->for($check)->create();
    Site::factory()->for($hidden)->create();

    $initial = $this->actingAs($operator)->get('/sites/'.$site->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page
        ->component('sites/settings')->where('site.name', 'Review site')->where('check_count', 1)
        ->where('incident_count', 1)->missing('result_count')->where('client.id', $client->id));
    $impact = $this->get('/sites/'.$site->id.'/settings', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->inertiaPage()['version'],
        'X-Inertia-Partial-Component' => 'sites/settings',
        'X-Inertia-Partial-Data' => 'result_count',
    ])->assertOk()->assertHeader('X-Inertia', 'true');
    expect($impact->json('props'))->toHaveKey('result_count')->not->toHaveKey('site');
    expect($impact->json('props.result_count'))->toBe(1);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($member)->get('/sites/'.$site->id.'/settings')->assertForbidden();
    $this->get('/sites/'.Site::query()->where('client_id', $hidden->id)->firstOrFail()->id.'/settings')->assertNotFound();
});

it('updates a site through existing validation and requires its exact display name before deletion', function (): void {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Before']);
    $check = Check::factory()->for($site)->create();
    $run = CheckRun::factory()->for($check)->create();
    $incident = Incident::factory()->for($check)->create();
    $this->actingAs($user)->patch('/sites/'.$site->id, ['friendly_name' => 'After', 'url' => $site->url, 'tags' => ['production'], 'checks' => []])->assertRedirect();
    expect($site->refresh()->friendly_name)->toBe('After');
    expect($site->tags->first()?->getTranslation('name', 'en'))->toBe('production');
    $this->delete('/sites/'.$site->id, ['name' => 'Before'])->assertSessionHasErrors('name');
    $this->assertDatabaseHas('sites', ['id' => $site->id]);
    $this->delete('/sites/'.$site->id, ['name' => 'After'])->assertRedirect('/sites');
    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    $this->assertDatabaseMissing('check_runs', ['id' => $run->id]);
    $this->assertDatabaseMissing('incidents', ['id' => $incident->id]);
});
