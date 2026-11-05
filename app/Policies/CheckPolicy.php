<?php

declare(strict_types=1);

namespace Heptapod\Policies;

use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Auth\Access\Response;

final readonly class CheckPolicy
{
    public function __construct(private SitePolicy $sites) {}

    public function view(User $user, Check $check): Response
    {
        return $this->sites->view($user, $check->site()->getQuery()->firstOrFail());
    }

    public function snooze(User $user, Check $check): Response
    {
        return $this->view($user, $check);
    }

    public function update(User $user, Check $check): Response
    {
        return $this->sites->update($user, $check->site()->getQuery()->firstOrFail());
    }
}
