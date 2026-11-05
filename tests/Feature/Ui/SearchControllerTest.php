<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
});

it('limits every search group and scopes all matches without leaking secrets', function (int $count): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $secret = 'search-real-secret';
    $visibleIncidentId = null;
    $addClients = static function (int $clientCount) use ($user, $secret, &$visibleIncidentId): void {
        $clients = Client::factory()->count($clientCount)->create(['name' => 'Matching client']);
        $user->clients()->attach($clients);
        foreach ($clients as $client) {
            $site = Site::factory()->for($client)->create(['friendly_name' => 'Matching site', 'crawler_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
            $check = Check::factory()->for($site)->create();
            $incident = Incident::factory()->for($check)->create(['evidence' => ['secret' => $secret]]);
            $visibleIncidentId ??= $incident->id;
        }
    };
    $addClients(2);
    $hiddenClient = Client::factory()->create(['name' => 'Matching hidden client']);
    $hidden = Site::factory()->for($hiddenClient)->create(['friendly_name' => 'Matching hidden']);
    $hiddenCheck = Check::factory()->for($hidden)->create();
    $hiddenIncident = Incident::factory()->for($hiddenCheck)->create();
    $this->withoutExceptionHandling();
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/search?q=Matching')->assertOk()->assertJsonCount(2, 'sites')->assertJsonCount(2, 'clients')->assertJsonCount(2, 'incidents');
    $fewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $addClients($count - 2);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->getJson('/search?q=Matching')->assertOk()->assertJsonCount(10, 'sites')->assertJsonCount(10, 'clients')->assertJsonCount(10, 'incidents');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($response->getContent())->not->toContain($secret);
    expect($response->getContent())->not->toContain('Matching hidden');
    expect(count($queries))->toBeLessThanOrEqual($fewQueries, 'search grew from '.$fewQueries.' to '.count($queries).' queries as each group grew from 2 to 10 results');
    // Upper budget: auth plus one bounded read per group, batched client avatars (36aca5a1a) and current incident presentations (e04c03b5d).
    expect($fewQueries)->toBeLessThanOrEqual(17, 'search used '.$fewQueries.' queries, over its budget of 17');
    if ($count === 300) {
        $siteQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "sites"') && str_contains($query['query'], 'CASE state'));
        expect($siteQuery)->not->toBeNull();
        expect(QueryPlanWork::forQuery($siteQuery['query'], $siteQuery['bindings']))->toBeLessThan(12 * $count);
        $incidentQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "incidents"') && str_contains($query['query'], 'order by "opened_at"'));
        expect($incidentQuery)->not->toBeNull();
        expect(QueryPlanWork::forQuery($incidentQuery['query'], $incidentQuery['bindings']))->toBeLessThan(12 * $count);
    }
    $this->getJson('/search?q=Matching%20hidden')->assertJsonCount(0, 'sites')->assertJsonCount(0, 'clients')->assertJsonCount(0, 'incidents');
    $this->getJson('/search?q=inc-'.$visibleIncidentId)->assertJsonPath('incidents.0.id', $visibleIncidentId)->assertJsonCount(1, 'incidents');
    $this->getJson('/search?q=INC-'.$hiddenIncident->id)->assertJsonCount(0, 'incidents');
    foreach (['INC-', 'inc-12x', 'INC-007', (string) $visibleIncidentId, 'INC-'.$visibleIncidentId.'0000000000000000000000'] as $notExact) {
        $this->getJson('/search?q='.$notExact)->assertJsonCount(0, 'incidents');
    }
})->with([30, 300]);

it('validates search input and returns no results for an empty query', function (): void {
    $this->get('/search')->assertRedirect('/login');
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user)->getJson('/search?q=')->assertOk()->assertExactJson(['sites' => [], 'clients' => [], 'incidents' => []]);
    $this->getJson('/search?q[]=%25')->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('ranks matching sites by severity before applying the group limit', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    foreach (range(1, 10) as $index) {
        $site = Site::factory()->create(['friendly_name' => 'A Match '.$index]);
        Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Succeeded]);
    }
    $expected = [];
    foreach ([['Z Match', CheckKey::Uptime, CheckResult::Failed], ['Y Match', CheckKey::Performance, CheckResult::Failed], ['X Match', CheckKey::Uptime, CheckResult::Errored], ['W Match', CheckKey::Uptime, CheckResult::Warning], ['V Match', CheckKey::Uptime, CheckResult::Pending]] as [$name, $key, $result]) {
        $site = Site::factory()->create(['friendly_name' => $name]);
        $check = Check::factory()->for($site)->create(['type' => $key, 'latest_run_result' => $result]);
        if ($name === 'Z Match') {
            Incident::factory()->for($check)->create();
        }
        $expected[] = $site->id;
    }

    $response = $this->actingAs($user)->getJson('/search?q=Match')->assertOk()->assertJsonCount(10, 'sites');
    expect(array_slice(array_column($response->json('sites'), 'id'), 0, 5))->toBe($expected);
});
