<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Queries\UpdateStatusReader;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\ReleaseNotes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class CheckForUpdates
{
    /** At most this many of the newest releases are read for their notes. */
    public const int ReleasePage = 20;

    public function __construct(private OutboundHttp $http, private InstanceSettings $settings) {}

    public function __invoke(): void
    {
        if (! config()->boolean('heptapod.updates.enabled_by_server') || ! $this->settings->updates_enabled) {
            return;
        }
        $url = config()->string('heptapod.updates.release_url');
        $now = CarbonImmutable::now('UTC');
        $request = new FetchRequest($url, headers: ['Accept' => 'application/vnd.github+json'],
            maxWireBytes: 32768, maxDecodedBytes: 32768, redirects: RedirectPolicy::Untrusted, maxRedirects: 2,
            timeoutSeconds: 10, publicTargetsOnly: true);
        $context = new CheckContext(0, 0, 0, 'update-check', $url, $now, $now->addSeconds(10), [], [], null);
        $result = $this->http->fetch($request, $context);
        $release = null;
        if (! $result->failure instanceof FetchFailure && $result->status === 200) {
            try {
                /** @var mixed $payload */
                $payload = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload) || ! UpdateStatusReader::isReleaseTag($payload['tag_name'] ?? null)
                    || ! is_string($payload['published_at'] ?? null)) {
                    throw new \UnexpectedValueException('GitHub release data is invalid.');
                }
                $release = ['latest_version' => $payload['tag_name'], 'published_at' => CarbonImmutable::parse($payload['published_at'])];
            } catch (\JsonException|\UnexpectedValueException|InvalidFormatException) {
                $release = null;
            }
        }
        $releases = $release === null ? null : $this->releases($url, $context);
        if ($release !== null && $releases === null) {
            Log::warning('Release list could not be read; release details are unavailable until the next check.');
        }
        $status = $release !== null ? 'reachable' : ($result->status === 200 ? 'invalid_response' : 'unreachable');
        $oldStatus = DB::table('update_checks')->where('id', 1)->value('status');
        if ($oldStatus !== $status && $status !== 'reachable') {
            Log::warning('Release update source status changed', ['state' => $status, 'http_status' => $result->status]);
        }
        DB::table('update_checks')->updateOrInsert(['id' => 1], [
            'status' => $status, 'http_status' => $result->status, 'checked_at' => $now,
            'latest_version' => $release['latest_version'] ?? null, 'published_at' => $release['published_at'] ?? null,
            'last_success_at' => $release === null ? DB::table('update_checks')->where('id', 1)->value('last_success_at') : $now,
            // Null when the list could not be read: the page then says so instead of showing older notes as complete.
            'releases' => $releases === null ? null : json_encode($releases, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The newest page of releases with their notes as plain text, from the list endpoint beside `/latest`.
     * `page_full` is counted before filtering, so the reader knows whether older releases may be missing.
     * Null when the source has no list endpoint or the read fails.
     *
     * @return array{page_full: bool, items: list<array{tag: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}>}|null
     */
    private function releases(string $latestUrl, CheckContext $context): ?array
    {
        if (! str_ends_with($latestUrl, '/latest')) {
            return null;
        }
        $result = $this->http->fetch(new FetchRequest(substr($latestUrl, 0, -7).'?per_page='.self::ReleasePage,
            headers: ['Accept' => 'application/vnd.github+json'], maxWireBytes: 1048576, maxDecodedBytes: 1048576,
            redirects: RedirectPolicy::Untrusted, maxRedirects: 2, timeoutSeconds: 10, publicTargetsOnly: true), $context);
        if ($result->failure instanceof FetchFailure || $result->status !== 200) {
            return null;
        }
        try {
            /** @var mixed $payload */
            $payload = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($payload) || ! array_is_list($payload)) {
            return null;
        }
        $items = [];
        foreach (array_slice($payload, 0, self::ReleasePage) as $item) {
            if (! is_array($item) || ($item['draft'] ?? true) !== false || ($item['prerelease'] ?? true) !== false
                || ! is_string($item['tag_name'] ?? null) || ! UpdateStatusReader::isReleaseTag($item['tag_name'])
                || ! is_string($item['published_at'] ?? null)) {
                continue;
            }
            try {
                $published = CarbonImmutable::parse($item['published_at'])->utc()->toIso8601String();
            } catch (InvalidFormatException) {
                continue;
            }
            $items[] = ['tag' => $item['tag_name'], 'published_at' => $published,
                ...ReleaseNotes::parse(is_string($item['body'] ?? null) ? $item['body'] : '')];
        }

        return ['page_full' => count($payload) >= self::ReleasePage, 'items' => $items];
    }
}
