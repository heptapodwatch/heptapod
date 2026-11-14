<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\RenewInvitationLink;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('heptapod:user:access-link {email : Account or invitation email} {--type= : invite (pending invitation) or reset (existing active account)}')]
final class CreateAccessLinkCommand extends Command
{
    public function getDescription(): string
    {
        return 'Print a one-time invitation or password-reset link for operators without SMTP. Run as the instance Owner; the link grants access by itself.';
    }

    public function getHelp(): string
    {
        return 'Owner-only. Share the link through a private channel. The link replaces any earlier link for this invitation or account.';
    }

    public function handle(RenewInvitationLink $renewLink): int
    {
        $type = $this->option('type');
        $email = $this->argument('email');
        if (! in_array($type, ['invite', 'reset'], true)) {
            $this->error('Supply --type=invite or --type=reset.');

            return self::FAILURE;
        }
        if (! is_string($email)) {
            $this->error('Supply one account or invitation email.');

            return self::FAILURE;
        }
        $email = Str::lower(trim($email));
        if ($type === 'invite') {
            $subject = Invitation::query()->where('email', $email)->first();
            if ($subject === null) {
                $this->error('No pending invitation for this email.');

                return self::FAILURE;
            }
            $url = $renewLink($subject);
            $expiresAt = $subject->expires_at;
        } else {
            $subject = User::query()->where('email', $email)->first();
            if ($subject === null || $subject->disabled_at !== null) {
                $this->error('No active account for this email.');

                return self::FAILURE;
            }
            $brokerName = config()->string('fortify.passwords');
            /** @var PasswordBroker $broker */
            $broker = Password::broker($brokerName);
            $token = $broker->createToken($subject);
            $url = url(route('password.reset', ['token' => $token, 'email' => $subject->getEmailForPasswordReset()], false));
            $expiresAt = now()->addMinutes(config()->integer('auth.passwords.'.$brokerName.'.expire'));
        }
        activity('audit')->event('access_link.issued')->performedOn($subject)
            ->withProperties(['type' => $type, 'email' => $email, 'expires_at' => $expiresAt->toIso8601String()])->log('access_link.issued');
        Log::info('Access link issued.', ['type' => $type, 'email' => $email]);
        $this->line('Link (valid until '.$expiresAt->utc()->toIso8601String().', single use):');
        $this->output->writeln($url, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
