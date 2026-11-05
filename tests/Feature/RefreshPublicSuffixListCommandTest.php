<?php

declare(strict_types=1);

use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->pslResources = sys_get_temp_dir().'/heptapod-psl-'.bin2hex(random_bytes(8)).'/resources';
    mkdir($this->pslResources, 0777, true);
    file_put_contents($this->pslResources.'/public_suffix_list.dat', 'original list');
    file_put_contents($this->pslResources.'/README.md', "- Download date: 2026-01-01\n");
    app()->setBasePath(dirname($this->pslResources));
});

afterEach(function (): void {
    app(Filesystem::class)->deleteDirectory(dirname($this->pslResources));
});

function pslRefreshHttp(FetchResult $result): FakeOutboundHttp
{
    $fake = (new FakeOutboundHttp)->respondWith(new FetchRequest(
        'https://publicsuffix.org/list/public_suffix_list.dat',
        maxWireBytes: 2097152, maxDecodedBytes: 2097152, redirects: RedirectPolicy::Off, timeoutSeconds: 30,
    ), $result);
    app()->instance(OutboundHttp::class, $fake);

    return $fake;
}

it('refuses production refresh before fetching or writing files', function (): void {
    app()->instance('env', 'production');
    $http = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $http);

    $this->artisan('heptapod:psl:refresh')
        ->expectsOutput(__('psl.refresh_source_only'))
        ->assertFailed();

    expect($http->requests)->toBeEmpty();
    expect(file_get_contents(resource_path('public_suffix_list.dat')))->toBe('original list');
    expect(file_get_contents(resource_path('README.md')))->toBe("- Download date: 2026-01-01\n");
});

it('atomically replaces a valid list and dates its attribution without truncating existing readers', function (): void {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    $body = "// ===BEGIN ICANN DOMAINS===\ncom\nio\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\ngithub.io\n// ===END PRIVATE DOMAINS===\n";
    $http = pslRefreshHttp(new FetchResult(200, $body));
    $reader = fopen(resource_path('public_suffix_list.dat'), 'r');
    try {
        $this->artisan('heptapod:psl:refresh')->assertSuccessful();

        expect(file_get_contents(resource_path('public_suffix_list.dat')))->toBe($body);
        expect(stream_get_contents($reader))->toBe('original list');
        expect(file_get_contents(resource_path('README.md')))->toBe("- Download date: 2026-10-02\n");
        expect(glob($this->pslResources.'/*'))->toHaveCount(2);
        expect($http->requests)->toHaveCount(1);
        expect($http->requests[0]['request'])
            ->url->toBe('https://publicsuffix.org/list/public_suffix_list.dat')
            ->redirects->toBe(RedirectPolicy::Off)
            ->maxWireBytes->toBe(2097152)
            ->maxDecodedBytes->toBe(2097152);
        expect($http->requests[0]['context']->deadline->diffInSeconds($http->requests[0]['context']->startedAt, absolute: true))->toBe(30.0);
    } finally {
        fclose($reader);
    }
});

it('rejects invalid or oversized downloads without changing either file', function (FetchResult $result): void {
    pslRefreshHttp($result);

    $this->artisan('heptapod:psl:refresh')->assertFailed();

    expect(file_get_contents(resource_path('public_suffix_list.dat')))->toBe('original list');
    expect(file_get_contents(resource_path('README.md')))->toBe("- Download date: 2026-01-01\n");
    expect(glob($this->pslResources.'/*'))->toHaveCount(2);
})->with([
    'empty' => new FetchResult(200, ''),
    'HTML' => new FetchResult(200, '<html>not a list</html>'),
    'empty private section' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\ncom\nio\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\n// ===END PRIVATE DOMAINS===\n"),
    'invalid rule' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\n\xff\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\ngithub.io\n// ===END PRIVATE DOMAINS===\n"),
    'parser-ignored ICANN section' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\nhttps://example.com\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\ngithub.io\n// ===END PRIVATE DOMAINS===\n"),
    'parser-ignored private section' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\ncom\nio\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\nhttps://example.com\n// ===END PRIVATE DOMAINS===\n"),
    'both sections parser-ignored' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\nhttps://example.com\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\nhttps://example.com\n// ===END PRIVATE DOMAINS===\n"),
    'truncated ICANN section' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\ncom\nio\n"),
    'truncated private section' => new FetchResult(200, "// ===BEGIN ICANN DOMAINS===\ncom\nio\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\ngithub.io\n"),
    'oversize' => new FetchResult(failure: new FetchFailure(FetchFailureKind::TooLarge)),
]);
