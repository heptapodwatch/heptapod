<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Contracts\Notifications\AlertMailRenderer;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as ConfigValidator;

final class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailRenderer::class, BladeMailRenderer::class);
        $channels = [MailChannel::class, SlackChannel::class, SlackWebhookChannel::class, DiscordChannel::class, DiscordWebhookChannel::class,
            GoogleChatChannel::class, MicrosoftTeamsChannel::class, TelegramChannel::class, PushoverChannel::class, NtfyChannel::class, WebhookChannel::class];
        foreach ($channels as $channel) {
            $this->app->singleton($channel);
        }
        $this->app->tag($channels, ChannelType::class);
        $this->app->bind(AlertPreviewRenderer::class, MailAlertPreviewRenderer::class);
        $this->app->bind(AlertMailRenderer::class, MailAlertPreviewRenderer::class);
        $this->callAfterResolving(ChannelManager::class, static function (ChannelManager $manager, Container $app): void {
            ChannelRegistry::registerDrivers($manager, $app);
        });
    }

    public function boot(): void
    {
        Validator::extend('webhook_scheme', static function (string $attribute, mixed $value, array $parameters, ConfigValidator $validator): bool {
            $values = $validator->getData()['values'] ?? [];

            return is_string($value) && (strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' || (is_array($values) && ($values['allow_insecure_http'] ?? false) === true));
        });
        Validator::replacer('webhook_scheme', static fn (string $message, string $attribute, string $rule, array $parameters, ConfigValidator $validator): string => __('validation.url', ['attribute' => $validator->getDisplayableAttribute($attribute)]));
        Validator::extend('webhook_provider_url', static function (string $attribute, mixed $value, array $parameters): bool {
            $url = is_string($value) ? parse_url($value) : false;
            if (! is_array($url) || isset($url['fragment']) || ($url['port'] ?? 443) !== 443) {
                return false;
            }
            $host = strtolower($url['host'] ?? '');
            $path = $url['path'] ?? '';
            if ($parameters[0] === 'discord') {
                return in_array($host, ['discord.com', 'ptb.discord.com', 'canary.discord.com', 'discordapp.com'], true)
                    && preg_match('~\A/api/(?:v[0-9]+/)?webhooks/[0-9]+/[A-Za-z0-9_-]+\z~', $path) === 1;
            }
            if ($parameters[0] !== 'google_chat') {
                throw new \LogicException('Unknown webhook URL format.');
            }
            parse_str($url['query'] ?? '', $query);

            return $host === 'chat.googleapis.com' && preg_match('~\A/v1/spaces/[A-Za-z0-9_-]+/messages\z~', $path) === 1
                && is_string($query['key'] ?? null) && $query['key'] !== '' && is_string($query['token'] ?? null) && $query['token'] !== '';
        });
        Validator::replacer('webhook_provider_url', static fn (string $message, string $attribute, string $rule, array $parameters): string => match ($parameters[0]) {
            'discord' => __('channels.validation.discord_webhook_url'),
            'google_chat' => __('channels.validation.google_chat_webhook_url'),
            default => throw new \LogicException('Unknown webhook URL format.'),
        });
        Validator::extend('webhook_header_name', static fn (string $attribute, mixed $value): bool => WebhookChannel::allowsHeaderName($value));
        Validator::replacer('webhook_header_name', static fn (): string => __('channels.validation.reserved_header'));
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'channels');
    }
}
