<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    $this->cgroup = storage_path('framework/testing/worker-memory-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($this->cgroup);
    config(['heptapod.health.worker_cgroup_path' => $this->cgroup]);
});

afterEach(function (): void {
    File::deleteDirectory($this->cgroup);
});

/** The shipped Horizon cap default from compose.prod.yaml, in bytes as the kernel reports memory.max. */
function shippedHorizonCapBytes(): int
{
    $limit = Yaml::parseFile(base_path('compose.prod.yaml'))['services']['horizon']['mem_limit'];
    expect($limit)->toMatch('/^\$\{HORIZON_MEMORY_LIMIT:-\d+m\}$/');
    preg_match('/:-(\d+)m\}$/', $limit, $match);
    expect(file_get_contents(base_path('.env.prod.example')))->toMatch('/^HORIZON_MEMORY_LIMIT='.$match[1].'m$/m');

    return (int) $match[1] * 1024 * 1024;
}

/** @return array<string, string> the installer's memory profile values from scripts/worker-memory-preflight.sh */
function installerProfiles(): array
{
    preg_match_all('/^((?:default|small)_(?:crawl_memory_limit_mb|horizon_memory_limit))=(\d+m?)$/m', (string) file_get_contents(base_path('scripts/worker-memory-preflight.sh')), $matches);
    expect($matches[1])->toHaveCount(4);

    return array_combine($matches[1], $matches[2]);
}

it('gives each installer memory profile exactly the cap its crawl limit needs', function (string $profile): void {
    $values = installerProfiles();
    $capMiB = (int) rtrim($values[$profile.'_horizon_memory_limit'], 'm');
    config(['heptapod.worker_memory.crawl_php_limit_mib' => (int) $values[$profile.'_crawl_memory_limit_mb']]);

    file_put_contents($this->cgroup.'/memory.max', ($capMiB * 1024 * 1024)."\n");
    $this->artisan('heptapod:worker-memory')->assertSuccessful();
    file_put_contents($this->cgroup.'/memory.max', (($capMiB - 1) * 1024 * 1024)."\n");
    $this->artisan('heptapod:worker-memory')->expectsOutputToContain("needs at least {$capMiB}m")->assertFailed();
})->with(['default', 'small']);

it('ships the default memory profile in compose, the environment example and the installer', function (): void {
    $values = installerProfiles();
    expect(shippedHorizonCapBytes())->toBe((int) rtrim($values['default_horizon_memory_limit'], 'm') * 1024 * 1024)
        ->and(file_get_contents(base_path('.env.prod.example')))->toMatch('/^HEPTAPOD_CRAWL_MEMORY_LIMIT_MB='.$values['default_crawl_memory_limit_mb'].'$/m')
        ->and(config('heptapod.worker_memory.crawl_php_limit_mib'))->toBe((int) $values['default_crawl_memory_limit_mb']);
});

it('boots the shipped worker cap and refuses the old 832m profile by name', function (): void {
    file_put_contents($this->cgroup.'/memory.max', shippedHorizonCapBytes()."\n");
    $this->artisan('heptapod:worker-memory')->assertSuccessful();

    file_put_contents($this->cgroup.'/memory.max', (832 * 1024 * 1024)."\n");
    $this->artisan('heptapod:worker-memory')
        ->expectsOutputToContain('HORIZON_MEMORY_LIMIT is 832m')
        ->assertFailed();
});

it('refuses an added permanent worker or a raised crawl concurrency without a matching budget', function (): void {
    file_put_contents($this->cgroup.'/memory.max', shippedHorizonCapBytes()."\n");
    // Defaults only: Horizon still deploys a supervisor that no environment plan names.
    config(['horizon.defaults.reports' => ['connection' => 'redis', 'queue' => ['reports'], 'maxProcesses' => 1]]);
    $this->artisan('heptapod:worker-memory')->expectsOutputToContain('HORIZON_MEMORY_LIMIT')->assertFailed();

    config(['horizon.defaults' => array_diff_key(config('horizon.defaults'), ['reports' => true])]);
    config(['horizon.defaults.checks-heavy.maxProcesses' => 2, 'horizon.defaults.checks-heavy.minProcesses' => 2]);
    $this->artisan('heptapod:worker-memory')->expectsOutputToContain('HEPTAPOD_CRAWL_CONCURRENCY=2')->assertFailed();
});

it('budgets the effective supervisors, including an environment-level crawl worker override', function (): void {
    // Horizon merges the environment plan over the defaults; the override alone must require the two-crawl budget.
    config(['horizon.environments.*.checks-heavy' => ['minProcesses' => 2, 'maxProcesses' => 2]]);
    file_put_contents($this->cgroup.'/memory.max', shippedHorizonCapBytes()."\n");
    $this->artisan('heptapod:worker-memory')->expectsOutputToContain('HEPTAPOD_CRAWL_CONCURRENCY=2 with HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768 needs at least 2990m')->assertFailed();

    file_put_contents($this->cgroup.'/memory.max', (2990 * 1024 * 1024)."\n");
    $this->artisan('heptapod:worker-memory')->assertSuccessful();
});

it('counts the consolidated role processes that share the worker container', function (): void {
    file_put_contents($this->cgroup.'/memory.max', shippedHorizonCapBytes()."\n");
    $this->artisan('heptapod:worker-memory', ['--extra-processes' => 3])->expectsOutputToContain('HORIZON_MEMORY_LIMIT is 1990m')->assertFailed();
});
