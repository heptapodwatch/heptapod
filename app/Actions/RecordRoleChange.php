<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

final class RecordRoleChange
{
    public function __invoke(RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        activity('audit')->performedOn($event->model)
            ->event($event instanceof RoleAttachedEvent ? 'roles.attached' : 'roles.detached')
            ->withProperties(['role_ids' => $event->rolesOrIds])
            ->log($event instanceof RoleAttachedEvent ? 'roles.attached' : 'roles.detached');
    }
}
