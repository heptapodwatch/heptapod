<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class CreateEmailDestination
{
    public function __construct(private ChannelRegistry $channels, private RequestRoutingReconciliation $reconcile) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): NotificationDestination
    {
        /** @var array{address: string, label: string, locale: string} $data */
        $data = Validator::make($attributes, ['address' => ['required', 'email:rfc', 'max:254'],
            'label' => ['required', 'string', 'max:255'], 'locale' => ['required', Rule::in(['en', 'de'])]])->validate();
        $config = $this->channels->get('mail')->configSchema()->validateConfig(['email' => $data['address']]);

        return DB::transaction(function () use ($data, $config): NotificationDestination {
            $channel = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::Team, 'channel' => 'mail', 'label' => $data['label'],
                'locale' => $data['locale'], 'config' => $config]);
            NotificationPreference::writeInitialGrid($channel->id, NotificationPreference::defaults(RecipientKind::Team));
            $this->reconcile->forChannel($channel);

            return $channel;
        });
    }
}
