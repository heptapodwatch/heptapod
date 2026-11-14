<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\CreateOwner;
use Heptapod\Exceptions\OwnerAlreadyExists;
use Heptapod\Models\User;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('heptapod:user:create-owner {--email= : Owner email} {--name= : Display name} {--password-stdin : Read password from stdin} {--if-missing : Skip if an Owner already exists}')]
final class CreateOwnerCommand extends Command
{
    public function getDescription(): string
    {
        return 'Create the first verified Owner account';
    }

    public function handle(CreateOwner $createOwner): int
    {
        $email = $this->option('email');
        $name = $this->option('name');
        if (! is_string($email) || ($name !== null && ! is_string($name))) {
            $this->error('Supply --email and an optional --name.');

            return self::FAILURE;
        }

        try {
            Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->validate();
            $passwordStdin = $this->option('password-stdin') === true;
            if ($passwordStdin) {
                $password = $this->readPassword();
            } else {
                $characters = str_split(Str::random(28).'aA1-');
                for ($index = count($characters) - 1; $index > 0; $index--) {
                    $swapIndex = random_int(0, $index);
                    [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
                }
                $password = implode('', $characters);
            }
            $user = $createOwner($email, $name ?? explode('@', $email, 2)[0], $password, $this->option('if-missing') === true, generatedPassword: ! $passwordStdin);
        } catch (ValidationException $exception) {
            $this->error('Owner creation failed: '.implode(', ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (OwnerAlreadyExists $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $user instanceof User) {
            $this->line('Owner already exists; no account or password changed.');

            return self::SUCCESS;
        }

        $this->line('Owner created: '.$user->email);
        if (! $passwordStdin) {
            $this->output->writeln('Password (shown once): '.$password, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function readPassword(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $password = stream_get_contents($stream ?? STDIN);
        if ($password === false) {
            throw new RuntimeException('Could not read password from stdin.');
        }

        return rtrim($password, "\r\n");
    }
}
