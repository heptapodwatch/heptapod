<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpdatesSettingsPageData extends Data
{
    /**
     * @param  array{installed_version: string, update_status: string, enabled: bool, enabled_by_server: bool, http_status: int|null, checked_at: string|null, last_success_at: string|null, latest_version: string|null, release_url: string|null, published_at: string|null, evaluated_at: string}  $updates
     * @param  array{disk: string, reachable: bool, archives: list<array{created_at: string, size_bytes: int}>, runs: list<array{id: int, requested_by: int|null, state: string, disk: string|null, http_status: int|null, requested_at: string, started_at: string|null, completed_at: string|null}>, schedule_utc: string, retention: array<string, int|null>, uses_default_retention: bool, observed_at: string}|null  $backups
     */
    public function __construct(
        public readonly array $updates,
        public readonly ?array $backups,
    ) {}
}
