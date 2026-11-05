<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\NotificationSchedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * PATCH /notification-schedules/{kind}/{id?}: quiet hours and the digest slot of one recipient (design §1.1). The
 * timezone is derived and not editable. Items already planned keep their due time; the next planning uses the new one.
 */
final readonly class UpdateNotificationSchedule
{
    /** @param array<string, mixed> $input */
    public function __invoke(RecipientKind $kind, ?int $userId, ?int $clientId, array $input): NotificationSchedule
    {
        /** @var array{quiet_start?: string|null, quiet_end?: string|null, quiet_weekends?: bool, digest_time?: string, digest_weekdays?: list<int>|null} $data */
        $data = Validator::make($input, [
            'quiet_start' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:quiet_end'],
            'quiet_end' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:quiet_start', 'different:quiet_start'],
            'quiet_weekends' => ['sometimes', 'boolean'],
            'digest_time' => ['sometimes', 'date_format:H:i'],
            'digest_weekdays' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            'digest_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
        ], ['digest_weekdays.min' => __('recipient_settings.schedule.no_days')], $this->attributeNames())->validate();
        if (array_key_exists('quiet_start', $data) !== array_key_exists('quiet_end', $data)) {
            throw ValidationException::withMessages(['quiet_end' => __('validation.required_with', ['attribute' => __('recipient_settings.schedule.quiet_until'),
                'values' => __('recipient_settings.schedule.quiet_from')])]);
        }

        return NotificationLocks::transaction(function () use ($kind, $userId, $clientId, $data): NotificationSchedule {
            $query = NotificationSchedule::query()->where('recipient_kind', $kind->value);
            match ($kind) {
                RecipientKind::Team => null,
                RecipientKind::User => $query->where('user_id', $userId),
                RecipientKind::Client => $query->where('client_id', $clientId),
            };
            $query->getQuery()->lockForUpdate();
            $schedule = $query->first() ?? NotificationSchedule::defaults($kind, $userId, $clientId);
            if (isset($data['digest_weekdays'])) {
                $data['digest_weekdays'] = array_map(intval(...), $data['digest_weekdays']);
                sort($data['digest_weekdays']);
            }
            $schedule->fill($data);
            if ($schedule->effectiveDigestWeekdays() === []) {
                throw ValidationException::withMessages(['digest_weekdays' => __('Quiet weekends exclude weekend digest days. Select at least one weekday or turn off quiet weekends.')]);
            }
            $schedule->save();

            return $schedule;
        });
    }

    /** @return array<string, string> Validation messages name the fields as the schedule form labels them. */
    private function attributeNames(): array
    {
        return array_map(static fn (string $key): string => (string) __($key), ['quiet_start' => 'recipient_settings.schedule.quiet_from',
            'quiet_end' => 'recipient_settings.schedule.quiet_until', 'quiet_weekends' => 'recipient_settings.schedule.weekends',
            'digest_time' => 'recipient_settings.schedule.digest_time', 'digest_weekdays' => 'recipient_settings.schedule.digest_days',
            'digest_weekdays.*' => 'recipient_settings.schedule.digest_days']);
    }
}
