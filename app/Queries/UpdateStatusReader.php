<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;

final readonly class UpdateStatusReader
{
    public function __construct(private InstanceSettings $settings) {}

    /** @return array{installed_version: string, update_status: string, enabled: bool, enabled_by_server: bool, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, evaluated_at: string} */
    public function read(): array
    {
        $version = config()->string('heptapod.version');
        $allowed = config()->boolean('heptapod.updates.enabled_by_server');
        $check = DB::table('update_checks')->where('id', 1)->first();
        /** @var (\stdClass&object{status: string, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null})|null $check */
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

        return ['installed_version' => $version, 'update_status' => $status,
            'enabled' => $this->settings->updates_enabled, 'enabled_by_server' => $allowed,
            'http_status' => $check?->http_status,
            'checked_at' => $check?->checked_at === null ? null : CarbonImmutable::parse($check->checked_at, 'UTC')->toIso8601String(),
            'last_success_at' => $check?->last_success_at === null ? null : CarbonImmutable::parse($check->last_success_at, 'UTC')->toIso8601String(),
            'latest_version' => $check?->latest_version, 'release_url' => $check?->release_url,
            'published_at' => $check?->published_at === null ? null : CarbonImmutable::parse($check->published_at, 'UTC')->toIso8601String(),
            'evaluated_at' => now()->toIso8601String()];
    }
}
