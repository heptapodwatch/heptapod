<?php

declare(strict_types=1);

namespace Heptapod\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;

/**
 * Credential mails (invitation, password reset, verification) are always Branded, whatever the instance mail design: a
 * recognizable sender is part of trusting a sign-in link. The channel test send follows the instance design, because it
 * shows what that channel will receive.
 */
final readonly class AccountMailFactory
{
    public function __construct(private InstanceSettings $settings) {}

    public function test(NotificationDestination $destination, User $actor, CarbonImmutable $at): MailModel
    {
        $locale = $destination->effectiveLocale();
        $headline = __('account_mail.test_headline', locale: $locale);

        return new MailModel(locale: $locale, subject: __('account_mail.test_subject', locale: $locale), preheader: $headline, headline: $headline,
            footerWhy: __('account_mail.test_why', locale: $locale), instance: $this->instance(), tone: MailTone::Recovered,
            lead: __('account_mail.test_lead', ['actor' => $actor->name, 'channel' => $destination->label,
                'address' => $destination->destinationConfig()->string('email'), 'sent' => $at->setTimezone($this->settings->timezone)->format('Y-m-d H:i T')], $locale),
            design: $this->settings->mail_design);
    }

    public function invitation(Invitation $invitation, string $url, string $locale): MailModel
    {
        // Deleting an inviter sets inviter_id to null without deleting the pending invitation.
        $actor = $invitation->inviter->name ?? __('account_mail.team', locale: $locale);
        $ids = $invitation->client_ids;
        $clients = $invitation->client_scope === ClientScope::All->value ? __('account_mail.all_clients', locale: $locale) : Client::query()->whereKey($ids)->pluck('name')->implode(', ');
        $params = ['actor' => $actor, 'instance' => $this->settings->name];
        $headline = __('account_mail.invite_headline', $params, $locale);

        return new MailModel(locale: $locale, subject: __('account_mail.invite_subject', $params, $locale), preheader: $headline, headline: $headline,
            footerWhy: __('account_mail.invite_why', locale: $locale), instance: $this->instance(),
            lead: __('account_mail.invite_lead', ['role' => __($invitation->role, locale: $locale), 'clients' => $clients,
                'expires' => $invitation->expires_at->setTimezone($this->settings->timezone)->format('Y-m-d H:i T')], $locale),
            primary: ['label' => __('account_mail.accept_invitation', locale: $locale), 'url' => $url], design: MailDesign::Branded);
    }

    public function passwordReset(User $user, string $url): MailModel
    {
        $locale = $user->locale ?? app()->getLocale();
        $minutes = config()->integer('auth.passwords.'.config()->string('auth.defaults.passwords').'.expire');
        $expiry = __('account_mail.reset_expiry', ['minutes' => $minutes], $locale);

        return new MailModel(locale: $locale, subject: __('account_mail.reset_subject', locale: $locale), preheader: $expiry,
            headline: __('account_mail.reset_headline', locale: $locale), footerWhy: __('account_mail.reset_why', locale: $locale), instance: $this->instance(),
            lead: __('account_mail.reset_lead', ['address' => $user->email, 'expiry' => $expiry], $locale),
            primary: ['label' => __('account_mail.choose_password', locale: $locale), 'url' => $url], design: MailDesign::Branded);
    }

    public function verification(User $user, string $url): MailModel
    {
        $locale = $user->locale ?? app()->getLocale();

        return new MailModel(locale: $locale, subject: __('account_mail.verify_subject', locale: $locale), preheader: __('account_mail.verify_preheader', locale: $locale),
            headline: __('account_mail.verify_headline', locale: $locale), footerWhy: __('account_mail.verify_why', ['address' => $user->email, 'host' => parse_url(config()->string('app.url'), PHP_URL_HOST)], $locale),
            instance: $this->instance(), lead: __('account_mail.verify_lead', locale: $locale),
            primary: ['label' => __('account_mail.verify_action', locale: $locale), 'url' => $url], design: MailDesign::Branded);
    }

    private function instance(): string
    {
        return $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST);
    }
}
