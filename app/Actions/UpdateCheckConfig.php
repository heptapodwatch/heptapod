<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\CheckSchedule;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Support\SecretMerge;
use Heptapod\Support\SecretReentry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateCheckConfig
{
    public function __construct(private CheckRegistry $registry, private ClaimService $claims, private DatabaseClock $clock) {}

    /** @param array<string, mixed> $settings
     * @param  array<string, mixed>|null  $expectedPreviousConfig
     */
    public function __invoke(Check $check, array $settings, #[\SensitiveParameter] ?array $expectedPreviousConfig = null): void
    {
        DB::transaction(function () use ($check, $settings, $expectedPreviousConfig): void {
            if ($check->exists) {
                $query = Check::query()->whereKey($check->id);
                NotificationLocks::forUpdate($query->getQuery());
                $check = $query->firstOrFail();
            }
            if (! in_array($check->type, $this->registry->keys(), true)) {
                throw ValidationException::withMessages(['type' => __('api.unsupported')]);
            }
            $reason = $this->registry->availability($check->type)->reason;
            if (! $check->exists && $reason !== null) {
                throw ValidationException::withMessages(['type' => __($reason)]);
            }
            $schedule = $this->validateSchedule($settings, $check->type === CheckKey::Uptime);
            $schema = $this->registry->get($check->type)->definition()->configSchema;
            $previous = $check->exists ? $check->executionConfig() : [];
            abort_if($expectedPreviousConfig !== null && $previous !== $expectedPreviousConfig, 409, __('checks.settings_changed'));
            $incoming = Arr::except($settings, ['frequency_value', 'frequency_unit', 'failed_notification_threshold']);
            $supplied = $incoming;
            foreach ($schema->fields as $name => $field) {
                if (array_key_exists($name, $incoming)) {
                    $incoming[$name] = SecretMerge::preserve($field, $incoming[$name], $previous[$name] ?? null, $name);
                }
            }
            /** @var array<string, mixed> $config */
            $config = array_replace($previous, $incoming);
            if (isset($config['location'])) {
                throw ValidationException::withMessages(['location' => __('api.unsupported_location')]);
            }
            $validated = $schema->validateConfig($config);
            $root = new Field(FieldType::Object, properties: $schema->fields);
            $previousTargets = $root->targetUrls($previous);
            foreach ($root->targetUrls($config) as $path => $url) {
                if (! isset($previousTargets[$path]) || SecretReentry::origin($previousTargets[$path]) !== SecretReentry::origin($url)) {
                    $errors = SecretReentry::errors($check->config_secrets ?? [], $supplied, $check->type->value.'_check_settings');
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
            }
            $check->setAttribute('config', $validated);
            if (isset($schedule['frequency_value'])) {
                $check->interval_seconds = $schedule['frequency_value'] * match ($schedule['frequency_unit'] ?? 'minute') {
                    'minute' => 60, 'hour' => 3600, 'day' => 86400,
                };
            }
            if (array_key_exists('failed_notification_threshold', $schedule)) {
                $minutes = $schedule['failed_notification_threshold'];
                if ($check->type === CheckKey::Uptime) {
                    $site = $check->site ?? throw new \LogicException('Uptime timing requires its site.');
                    $site->update(['alert_after_minutes' => $minutes,
                        'alert_recovery_checks' => $minutes === null ? null : AlertSensitivity::recoveryChecksFor($minutes)]);
                } else {
                    $check->failure_threshold = $minutes ?? throw new \LogicException('Only uptime timing may inherit.');
                }
            }
            if ($check->exists && $check->executionConfig() !== $previous) {
                $this->claims->revoke($check);
            } elseif ($check->exists && $check->isDirty('interval_seconds')) {
                $check->next_run_at = CheckSchedule::nextSlot($check->id, $check->interval_seconds, $this->clock->now());
            } elseif (! $check->exists) {
                $check->next_run_at = $this->clock->now();
            }
            $check->save();
        });
        $check->refresh();
    }

    /** @param array<string, mixed> $settings
     * @return array{frequency_value?: int, frequency_unit?: 'minute'|'hour'|'day', failed_notification_threshold?: int|null}
     */
    public function validateSchedule(array $settings, bool $uptime = false): array
    {
        if (is_string($settings['failed_notification_threshold'] ?? null) && trim($settings['failed_notification_threshold']) === '') {
            $settings['failed_notification_threshold'] = null;
        }

        /** @var array{frequency_value?: int|numeric-string, frequency_unit?: 'minute'|'hour'|'day', failed_notification_threshold?: int|numeric-string|null} $validated */
        $validated = Validator::make(Arr::only($settings, ['frequency_value', 'frequency_unit', 'failed_notification_threshold']), [
            'frequency_value' => ['sometimes', 'filled', 'integer', 'min:1', 'max:60'], 'frequency_unit' => ['sometimes', 'filled', 'in:minute,hour,day'],
            'failed_notification_threshold' => ['sometimes', $uptime ? 'nullable' : 'filled', 'integer',
                'min:'.AlertSensitivity::MinStoredMinutes, 'max:'.AlertSensitivity::MaxStoredMinutes],
        ])->validate();

        return [
            ...(isset($validated['frequency_value']) ? ['frequency_value' => (int) $validated['frequency_value']] : []),
            ...(isset($validated['frequency_unit']) ? ['frequency_unit' => $validated['frequency_unit']] : []),
            ...(array_key_exists('failed_notification_threshold', $validated)
                ? ['failed_notification_threshold' => $validated['failed_notification_threshold'] === null ? null : (int) $validated['failed_notification_threshold']]
                : []),
        ];
    }
}
