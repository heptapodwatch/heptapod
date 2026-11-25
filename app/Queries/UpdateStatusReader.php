<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class UpdateStatusReader
{
    /**
     * The one gate for a release tag, at ingestion and on read: Heptapod publishes only plain `vX.Y.Z` tags.
     * Up to four digits per part keeps every accepted tag comparable by Composer's parser, which rejects long majors.
     */
    public static function isReleaseTag(mixed $tag): bool
    {
        return is_string($tag) && preg_match('/^v\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $tag) === 1;
    }

    /** Release links are built from the configured release page and a valid tag, never taken from the source. */
    public static function releaseUrl(string $tag): string
    {
        return config()->string('heptapod.updates.release_page_url').'/tag/'.$tag;
    }

    public function __construct(private InstanceSettings $settings) {}

    /** @return array{installed_version: string, update_status: string, enabled: bool, enabled_by_server: bool, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, evaluated_at: string, releases: list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}>|null, releases_truncated: bool, security: bool} */
    public function read(): array
    {
        $version = config()->string('heptapod.version');
        $allowed = config()->boolean('heptapod.updates.enabled_by_server');
        $check = DB::table('update_checks')->where('id', 1)->first();
        /** @var (\stdClass&object{status: string, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, published_at: string|null, releases: string|null})|null $check */
        if ($check?->latest_version !== null && ! self::isReleaseTag($check->latest_version)) {
            // Stored before the gate existed: never shown, never compared, never offered as an update.
            Log::warning('Stored release tag is not a published vX.Y.Z tag; ignoring it.');
            $check->status = 'invalid_response';
            $check->latest_version = $check->published_at = null;
        }
        $status = match (true) {
            ! $allowed => 'disabled_by_server',
            ! $this->settings->updates_enabled => 'disabled_by_user',
            $check === null => 'not_checked',
            $check->status !== 'reachable' => $check->status,
            $version === 'unknown' || str_starts_with($version, 'sha-') || ! (new VersionParser)->isValid($version) => 'development',
            Comparator::greaterThan((new VersionParser)->normalize($check->latest_version ?? throw new \LogicException('Reachable update needs a tag.')),
                (new VersionParser)->normalize($version)) => 'available',
            default => 'current',
        };

        $skipped = $status === 'available' && $check !== null ? $this->skipped($check->releases, $version, $check->latest_version ?? '') : null;

        return ['installed_version' => $version, 'update_status' => $status,
            'enabled' => $this->settings->updates_enabled, 'enabled_by_server' => $allowed,
            'http_status' => $check?->http_status,
            'checked_at' => $check?->checked_at === null ? null : CarbonImmutable::parse($check->checked_at, 'UTC')->toIso8601String(),
            'last_success_at' => $check?->last_success_at === null ? null : CarbonImmutable::parse($check->last_success_at, 'UTC')->toIso8601String(),
            'latest_version' => $check?->latest_version,
            'release_url' => $check?->latest_version === null ? null : self::releaseUrl($check->latest_version),
            'published_at' => $check?->published_at === null ? null : CarbonImmutable::parse($check->published_at, 'UTC')->toIso8601String(),
            'evaluated_at' => now()->toIso8601String(),
            'releases' => $skipped['releases'] ?? null, 'releases_truncated' => $skipped['truncated'] ?? false,
            'security' => array_any($skipped['releases'] ?? [], static fn (array $release): bool => $release['security'] !== [])];
    }

    /**
     * The stored releases after the installed one up to the latest, newest first; null releases when the list could not be read.
     * Truncated when the stored page was full and never reached the installed release, so older ones may be missing.
     *
     * @return array{releases: list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}>|null, truncated: bool}
     */
    private function skipped(?string $json, string $installed, string $latest): array
    {
        if ($json === null) {
            return ['releases' => null, 'truncated' => false];
        }
        /** @var array{page_full: bool, items: list<array{tag: string, published_at: string, headline: string|null, before: list<string>, security: list<string>, body?: string, body_truncated?: bool}>} $page */
        $page = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $parser = new VersionParser;
        $items = array_filter($page['items'], static fn (array $release): bool => self::isReleaseTag($release['tag']));
        $releases = array_values(array_map(static fn (array $release): array => ['tag' => $release['tag'], 'url' => self::releaseUrl($release['tag']),
            'published_at' => $release['published_at'], 'headline' => $release['headline'], 'before' => $release['before'], 'security' => $release['security']],
            array_filter($items, static fn (array $release): bool => Comparator::greaterThan($parser->normalize($release['tag']), $parser->normalize($installed))
                && Comparator::lessThanOrEqualTo($parser->normalize($release['tag']), $parser->normalize($latest)))));
        usort($releases, static fn (array $a, array $b): int => version_compare($b['tag'], $a['tag']));
        $reachesInstalled = array_any($items, static fn (array $release): bool => Comparator::lessThanOrEqualTo($parser->normalize($release['tag']), $parser->normalize($installed)));

        return ['releases' => $releases, 'truncated' => $page['page_full'] && ! $reachesInstalled];
    }

    /**
     * The stored full notes by tag, raw and capped. Only the Updates page renders them; `read()` (the shell prop,
     * System health) never carries them.
     *
     * @return array<string, array{body: string, body_truncated: bool}>
     */
    public function notes(): array
    {
        $json = DB::table('update_checks')->where('id', 1)->value('releases');
        if (! is_string($json)) {
            return [];
        }
        /** @var array{items: list<array{tag: string, body?: string, body_truncated?: bool}>} $page */
        $page = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $notes = [];
        foreach ($page['items'] as $release) {
            if (self::isReleaseTag($release['tag']) && isset($release['body'])) {
                $notes[$release['tag']] = ['body' => $release['body'], 'body_truncated' => $release['body_truncated'] ?? false];
            }
        }

        return $notes;
    }
}
