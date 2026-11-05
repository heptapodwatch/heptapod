<?php

declare(strict_types=1);

namespace Heptapod\Observers;

use Heptapod\Events\LiveSignal;
use Heptapod\Models\Client;

final class ClientLiveObserver
{
    public function created(Client $client): void
    {
        LiveSignal::forClient($client);
    }

    public function updated(Client $client): void
    {
        LiveSignal::forClient($client);
    }

    public function deleted(Client $client): void
    {
        LiveSignal::forClient($client);
    }
}
