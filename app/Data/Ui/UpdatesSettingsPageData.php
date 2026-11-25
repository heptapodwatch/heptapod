<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpdatesSettingsPageData extends Data
{
    /**
     * @param  array{installed_version: string, update_status: string, enabled: bool, enabled_by_server: bool, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, evaluated_at: string, releases: list<array{tag: string, url: string, published_at: string, headline: string|null, before: list<string>, security: list<string>, notes_html: string|null, notes_truncated: bool}>|null, releases_truncated: bool, security: bool}  $updates
     * @param  array{disk: string, reachable: bool, archives: list<array{created_at: string, size_bytes: int}>, archives_pagination: PaginationData, runs: list<array{id: int, requested_by: int|null, state: string, disk: string|null, http_status: int|null, requested_at: string, started_at: string|null, completed_at: string|null}>, runs_pagination: PaginationData, latest_run: array{id: int, requested_by: int|null, state: string, disk: string|null, http_status: int|null, requested_at: string, started_at: string|null, completed_at: string|null}|null, has_active_run: bool, schedule_utc: string, retention: array<string, int|null>, uses_default_retention: bool, cooldown_until: string|null, observed_at: string}|null  $backups
     */
    public function __construct(
        public readonly array $updates,
        public readonly ?array $backups,
    ) {}
}
