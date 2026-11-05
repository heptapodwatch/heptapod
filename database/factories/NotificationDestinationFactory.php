<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A team mail channel with every category off; tests turn on exactly the cells they route (tests/Support/NotificationChannels.php).
 *
 * @extends Factory<NotificationDestination>
 */
final class NotificationDestinationFactory extends Factory
{
    #[\Override]
    protected $model = NotificationDestination::class;

    public function definition(): array
    {
        return [
            'recipient_kind' => RecipientKind::Team, 'label' => fake()->word(), 'channel' => 'mail', 'locale' => 'en', 'enabled' => true,
            'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => fake()->safeEmail()]),
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(static function (NotificationDestination $channel): void {
            NotificationPreference::writeGrid($channel->id, array_map(static fn (): NotificationMode => NotificationMode::Off,
                NotificationPreference::defaults($channel->recipient_kind)));
        });
    }
}
