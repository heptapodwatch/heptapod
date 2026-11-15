<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

// Synthetic values are split so the guard's own --all run does not flag this file.
const PRIVATE_HOME_PATH = '/ho'.'me/alice/';
const PRIVATE_TAILNET_HOST = 'box.tail1234.t'.'s.net';
const PRIVATE_EMAIL = 'alice@'.'corp-mail.de';
// The app's .env exports GIT_COMMON_DIR, and hooks export the others; each would point git at the real repository.
const GIT_ISOLATION = ['GIT_COMMON_DIR' => false, 'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false];

beforeEach(function (): void {
    $this->repository = sys_get_temp_dir().'/private-info-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->repository.'/scripts');
    $this->git = function (string ...$arguments): string {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', ...$arguments], $this->repository, GIT_ISOLATION);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $this->guard = function (array $arguments, string $input = ''): Process {
        $process = new Process([PHP_BINARY, base_path('scripts/check-private-info.php'), ...$arguments], $this->repository, GIT_ISOLATION, $input);
        $process->run();

        return $process;
    };
    ($this->git)('init', '--quiet', '--initial-branch=main');
    File::put($this->repository.'/scripts/private-info-exceptions.json', json_encode([
        'strings' => ['tests/fixture.yml' => ['vendor@'.'upstream-api.io']],
    ]));
    File::put($this->repository.'/.git/info/private-info-patterns', "# customer names\nsecret-customer\n");
});

afterEach(function (): void {
    File::deleteDirectory($this->repository);
    File::delete($this->repository.'-pr-body.md');
});

test('every tracked file is checked against the generic and local patterns', function (): void {
    File::put($this->repository.'/notes.md', 'Logs live in '.PRIVATE_HOME_PATH."logs.\nOpen https://".PRIVATE_TAILNET_HOST.'/ or mail '.PRIVATE_EMAIL.".\nThe secret-customer site.\n");
    File::ensureDirectoryExists($this->repository.'/docs');
    File::put($this->repository.'/docs/install.md', "Open http://localhost:8080 after the install.\n");
    File::ensureDirectoryExists($this->repository.'/tests');
    // Negatives: reserved example domains, an excepted fixture address, a local URL outside docs/, an SSH remote and URL
    // credentials (neither is an email address). A longer address around the excepted one still fails.
    File::put($this->repository.'/tests/fixture.yml', 'contact: vendor@'.'upstream-api.io'."\nsupport: private-vendor@".'upstream-api.io'."\nowner: owner@example.com\nreply: team@site.test\nurl: http://localhost:8080\nremote: git@".'github.com'.":org/repo.git\ntoken: https://token@".'github.com'."/org/repo\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'chore: seed');

    $guard = ($this->guard)(['--all']);

    expect($guard->getExitCode())->toBe(1)
        ->and(explode("\n", trim($guard->getErrorOutput())))->toBe([
            'Private info guard failed:',
            'docs/install.md:1: local URL in user-facing docs: http://localhost:8080',
            'notes.md:3: local pattern line 2: secret-customer',
            'notes.md:1: home path: '.PRIVATE_HOME_PATH,
            'notes.md:2: tailnet host: '.PRIVATE_TAILNET_HOST,
            'notes.md:2: email address: '.PRIVATE_EMAIL,
            'tests/fixture.yml:2: email address: private-vendor@'.'upstream-api.io',
            'Remove the private value, or add a reviewed exact exception to scripts/private-info-exceptions.json.',
        ]);
});

test('a base range checks changed files, unpushed commit messages and a pull request body', function (): void {
    File::put($this->repository.'/old.md', 'Pushed long ago: '.PRIVATE_EMAIL."\n");
    ($this->git)('add', '.');
    ($this->git)('commit', '--quiet', '-m', 'chore: seed');
    ($this->git)('branch', 'base');
    File::put($this->repository.'/new.md', "Clean change.\n");
    ($this->git)('add', 'new.md');
    ($this->git)('commit', '--quiet', '-m', "fix: clean subject\n\nReproduced in ".PRIVATE_HOME_PATH.'checkout.');
    $commit = substr(($this->git)('rev-parse', 'HEAD'), 0, 12);
    File::put($this->repository.'-pr-body.md', 'Review at https://'.PRIVATE_TAILNET_HOST."/sites\n");

    $guard = ($this->guard)(['--base', 'base', '--text', $this->repository.'-pr-body.md']);

    expect($guard->getExitCode())->toBe(1)
        ->and(explode("\n", trim($guard->getErrorOutput())))->toBe([
            'Private info guard failed:',
            'commit '.$commit.' message:3: home path: '.PRIVATE_HOME_PATH,
            'text '.$this->repository.'-pr-body.md:1: tailnet host: '.PRIVATE_TAILNET_HOST,
            'Remove the private value, or add a reviewed exact exception to scripts/private-info-exceptions.json.',
        ]);
});

test('the pre-push input selects the messages of the pushed commits that no remote has yet', function (): void {
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'chore: already pushed from '.PRIVATE_HOME_PATH);
    ($this->git)('update-ref', 'refs/remotes/origin/main', 'HEAD');
    ($this->git)('switch', '--quiet', '-c', 'feature');
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'feat: clean');
    ($this->git)('commit', '--quiet', '--allow-empty', '-m', 'fix: seen on '.PRIVATE_TAILNET_HOST);
    $pushed = ($this->git)('rev-parse', 'HEAD');
    // The pushed branch is not the checked-out one.
    ($this->git)('switch', '--quiet', 'main');
    $zero = str_repeat('0', 40);

    $guard = ($this->guard)(['--pushed'], "refs/heads/feature {$pushed} refs/heads/feature {$zero}\n(delete) {$zero} refs/heads/gone {$pushed}\n");

    expect($guard->getExitCode())->toBe(1)
        ->and(explode("\n", trim($guard->getErrorOutput())))->toBe([
            'Private info guard failed:',
            'commit '.substr($pushed, 0, 12).' message:1: tailnet host: '.PRIVATE_TAILNET_HOST,
            'Remove the private value, or add a reviewed exact exception to scripts/private-info-exceptions.json.',
        ]);
});
