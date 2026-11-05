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
        $channels = [MailChannel::class, SlackChannel::class, DiscordChannel::class, MicrosoftTeamsChannel::class, TelegramChannel::class, PushoverChannel::class, NtfyChannel::class, WebhookChannel::class];
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
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'channels');
    }
}
