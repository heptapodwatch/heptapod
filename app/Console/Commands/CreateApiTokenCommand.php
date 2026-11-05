<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\CreateApiToken;
use Heptapod\Models\User;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heptapod:token:create {user : User email} {--name= : Token display name (required)} {--abilities= : Comma-separated API abilities (required)}')]
final class CreateApiTokenCommand extends Command
{
    public function getDescription(): string
    {
        return __('api.token_description');
    }

    public function handle(CreateApiToken $create): int
    {
        $email = $this->argument('user');
        $name = $this->option('name');
        $abilities = $this->option('abilities');
        if (! is_string($name) || ! is_string($abilities)) {
            $this->error(__('api.token_options_required'));

            return self::FAILURE;
        }
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->line($create($user, $name, explode(',', $abilities)));

        return self::SUCCESS;
    }
}
