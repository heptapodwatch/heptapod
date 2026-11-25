<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Heptapod\Actions\CheckForUpdates;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class UpdateStatusReader
{
    /** Heptapod publishes only plain `vX.Y.Z` tags; the tag is pasted into shell commands, so nothing else may reach the UI. */
    public const string ReleaseTag = '/^v\d+\.\d+\.\d+$/D';

    public function __construct(private InstanceSettings $settings) {}

    /** @return array{installed_version: string, update_status: string, enabled: bool, enabled_by_server: bool, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, evaluated_at: string, releases: list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}>|null, releases_truncated: bool, security: bool} */
    public function read(): array
    {
        $version = config()->string('heptapod.version');
        $allowed = config()->boolean('heptapod.updates.enabled_by_server');
        $check = DB::table('update_checks')->where('id', 1)->first();
        /** @var (\stdClass&object{status: string, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, releases?: string|null})|null $check */
        if ($check?->latest_version !== null && preg_match(self::ReleaseTag, $check->latest_version) !== 1) {
            // Stored before ingestion required the published format: never shown, never offered as an update.
            Log::warning('Stored release tag is not a published vX.Y.Z tag; ignoring it.');
            $check->status = 'invalid_response';
            $check->latest_version = $check->release_url = $check->published_at = null;
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

        $skipped = $status === 'available' && $check !== null ? $this->skipped($check->releases ?? null, $version, $check->latest_version ?? '') : null;

        return ['installed_version' => $version, 'update_status' => $status,
            'enabled' => $this->settings->updates_enabled, 'enabled_by_server' => $allowed,
            'http_status' => $check?->http_status,
            'checked_at' => $check?->checked_at === null ? null : CarbonImmutable::parse($check->checked_at, 'UTC')->toIso8601String(),
            'last_success_at' => $check?->last_success_at === null ? null : CarbonImmutable::parse($check->last_success_at, 'UTC')->toIso8601String(),
            'latest_version' => $check?->latest_version, 'release_url' => $check?->release_url,
            'published_at' => $check?->published_at === null ? null : CarbonImmutable::parse($check->published_at, 'UTC')->toIso8601String(),
            'evaluated_at' => now()->toIso8601String(),
            'releases' => $skipped['releases'] ?? null, 'releases_truncated' => $skipped['truncated'] ?? false,
            'security' => array_any($skipped['releases'] ?? [], static fn (array $release): bool => $release['security'] !== [])];
    }

    /**
     * The stored releases after the installed one up to the latest, newest first; null releases when no list was read.
     * Truncated when the stored page ends before the installed release, so there may be more.
     *
     * @return array{releases: list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}>|null, truncated: bool}
     */
    private function skipped(?string $json, string $installed, string $latest): array
    {
        if ($json === null) {
            return ['releases' => null, 'truncated' => false];
        }
        /** @var list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>}> $stored */
        $stored = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $parser = new VersionParser;
        $releases = array_values(array_filter($stored, static fn (array $release): bool => preg_match(self::ReleaseTag, $release['tag']) === 1
            && Comparator::greaterThan($parser->normalize($release['tag']), $parser->normalize($installed))
            && Comparator::lessThanOrEqualTo($parser->normalize($release['tag']), $parser->normalize($latest))));
        usort($releases, static fn (array $a, array $b): int => version_compare($b['tag'], $a['tag']));
        $reachesInstalled = array_any($stored, static fn (array $release): bool => preg_match(self::ReleaseTag, $release['tag']) === 1
            && Comparator::lessThanOrEqualTo($parser->normalize($release['tag']), $parser->normalize($installed)));

        return ['releases' => $releases, 'truncated' => ! $reachesInstalled && count($stored) >= CheckForUpdates::ReleasePage];
    }
}
