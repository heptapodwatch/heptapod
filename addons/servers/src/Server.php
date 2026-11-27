<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $token_hash
 * @property int|null $client_id
 * @property CarbonImmutable|null $last_report_at
 * @property string|null $agent_version
 * @property string|null $agent_os
 * @property string|null $agent_arch
 * @property string|null $agent_distro
 * @property string|null $agent_kernel
 * @property string|null $hostname
 * @property list<string>|null $ips
 * @property array{mounts?: list<array{mount: string, percent?: int, minutes?: int}>, memory_percent?: int, memory_minutes?: int, load_per_core?: int|float, load_minutes?: int, no_report_minutes?: int, ignored_mounts?: list<string>} $settings only what differs from the instance defaults
 * @property CarbonImmutable $created_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class Server extends Model
{
    /** An unsaved server; issueToken() stores it. */
    public static function named(string $name): self
    {
        return new self()->forceFill(['public_id' => Str::lower((string) Str::ulid()), 'name' => $name]);
    }

    /** Saves a new token hash: any previous token stops working at once, and the plain token exists only in the return value. */
    public function issueToken(): ServerToken
    {
        $token = ServerToken::issue($this->public_id);
        $this->forceFill(['token_hash' => $token->hash()])->save();

        return $token;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_report_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'ips' => 'array',
            'settings' => 'array',
        ];
    }
}
