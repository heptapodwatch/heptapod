<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Outcome of every outgoing mail through the instance transport, kept in Redis beside the other health timestamps.
 * Successes come from the framework MessageSent event; failures from the senders that catch transport exceptions.
 * Mail sends run in parallel workers, so every read-modify-write holds a short Redis lock.
 */
final class MailTransportRecord
{
    private const string Key = 'heptapod:mail-transport:outcome';

    public function recordSuccess(CarbonImmutable $at): void
    {
        $this->update(static fn (array $stored): array => [...$stored,
            'last_success_at' => $at->utc()->toIso8601String(), 'consecutive_failures' => 0, 'failing_since' => null]);
    }

    public function recordFailure(CarbonImmutable $at, string $diagnosis): void
    {
        $this->update(static fn (array $stored): array => [...$stored,
            'consecutive_failures' => $stored['consecutive_failures'] + 1,
            'failing_since' => $stored['failing_since'] ?? $at->utc()->toIso8601String(),
            'last_failure_at' => $at->utc()->toIso8601String(), 'last_failure_diagnosis' => $diagnosis]);
    }

    /** Seed mail sent before transport tracking on the write path of the evaluator, never while rendering System health. */
    public function seedFromDeliveries(): void
    {
        $stored = $this->stored();
        if ($stored['last_success_at'] !== null || ($stored['history_seeded'] ?? false)) {
            return;
        }
        // The evaluator serializes seeding; do not block live mail outcomes during the history scan.
        /** @var string|null $lastSentAt */
        $lastSentAt = DB::table('deliveries')
            ->join('notification_destinations', 'notification_destinations.id', '=', 'deliveries.destination_id')
            ->where('notification_destinations.channel', 'mail')
            ->where('deliveries.state', DeliveryState::Sent->value)
            ->max('deliveries.sent_at');

        $this->update(static function (array $stored) use ($lastSentAt): array {
            if ($stored['last_success_at'] !== null || ($stored['history_seeded'] ?? false)) {
                return $stored;
            }

            // Remember an empty history too, so instances with no sent mail do not rescan it.
            return [...$stored, 'history_seeded' => true,
                'last_success_at' => $lastSentAt === null ? null : CarbonImmutable::parse($lastSentAt)->utc()->toIso8601String()];
        });
    }

    public function read(): MailTransportHealth
    {
        $stored = $this->stored();

        return new MailTransportHealth($stored['last_success_at'], $stored['consecutive_failures'], $stored['failing_since'], $stored['last_failure_at'], $stored['last_failure_diagnosis']);
    }

    /** @param callable(array{last_success_at: string|null, consecutive_failures: int, failing_since: string|null, last_failure_at: string|null, last_failure_diagnosis: string|null, history_seeded?: bool}): array<string, mixed> $change */
    private function update(callable $change): void
    {
        $locks = Cache::store('redis')->getStore();
        if (! $locks instanceof LockProvider) {
            throw new \LogicException('The Redis cache store must provide locks.');
        }
        $locks->lock(self::Key.':lock', 10)->block(5, fn (): bool => Cache::store('redis')->forever(self::Key, $change($this->stored())));
    }

    /** @return array{last_success_at: string|null, consecutive_failures: int, failing_since: string|null, last_failure_at: string|null, last_failure_diagnosis: string|null, history_seeded?: bool} */
    private function stored(): array
    {
        $value = Cache::store('redis')->get(self::Key) ?? ['last_success_at' => null, 'consecutive_failures' => 0, 'failing_since' => null, 'last_failure_at' => null, 'last_failure_diagnosis' => null];
        if (! is_array($value) || ! isset($value['consecutive_failures']) || ! is_int($value['consecutive_failures'])) {
            throw new UnexpectedValueException('Mail transport outcome in the health cache is invalid.');
        }
        foreach (['last_success_at', 'failing_since', 'last_failure_at', 'last_failure_diagnosis'] as $field) {
            if (! array_key_exists($field, $value) || ($value[$field] !== null && ! is_string($value[$field]))) {
                throw new UnexpectedValueException('Mail transport outcome in the health cache is invalid.');
            }
        }

        if (array_key_exists('history_seeded', $value) && ! is_bool($value['history_seeded'])) {
            throw new UnexpectedValueException('Mail transport history seed marker in the health cache is invalid.');
        }

        /** @var array{last_success_at: string|null, consecutive_failures: int, failing_since: string|null, last_failure_at: string|null, last_failure_diagnosis: string|null, history_seeded?: bool} $value */
        return $value;
    }
}
