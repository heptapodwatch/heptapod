<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Addon-owned list of supported source settings and optional definition importer. */
final readonly class OhDearImportMapping
{
    /** Core-owned schedule settings that every check type imports. */
    public const array ScheduleSettings = ['frequency_value', 'frequency_unit', 'failed_notification_threshold'];

    /** @var list<string> Settings of the addon plus the schedule settings. */
    public array $settings;

    /** @param list<string> $settings
     * @param  class-string<OhDearDefinitionImporter>|null  $definitions
     */
    public function __construct(array $settings, public ?string $definitions = null)
    {
        $this->settings = [...$settings, ...self::ScheduleSettings];
    }
}
