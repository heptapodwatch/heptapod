<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Heptapod\Contracts\Http\DeliveryHttp;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Contracts\Notifications\SystemIssueNotification;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Contracts\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Telegram\Exceptions\CouldNotSendNotification as TelegramFailure;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use Throwable;

/** Own HTTP transports, registered as "heptapod.<key>" drivers. Every request goes through the guarded delivery HTTP seam. */
abstract class TransportChannel implements ChannelType
{
    public function __construct(private readonly DeliveryHttp $deliveryHttp) {}

    final public function send(ChannelRecipient $recipient, Notification $notification): DeliveryResult
    {
        if (! $notification instanceof AlertNotification && ! $notification instanceof SystemIssueNotification) {
            throw new \LogicException('Transport channels render alert and system issue notifications only.');
        }
        $destination = $recipient->destination;
        $message = $notification->message;
        $idempotencyKey = $notification->idempotencyKey;
        // Validate again at the transport boundary, including configurations reconstructed from storage.
        $schema = $this->configSchema();
        $secrets = (array) new Field(FieldType::Object, properties: $schema->fields)->split($destination->values())[1];
        if (array_any(Arr::flatten($secrets), SecretMinimum::tooShort(...))) {
            return new DeliveryResult(DeliveryState::Failed, 'notifications.secret_too_short', diagnosis: __('notifications.secret_too_short', locale: $destination->locale));
        }
        $schema->validateConfig($destination->values());
        try {
            return $this->deliver(new RenderedAlert($message, $destination->locale, $destination->timezone, $destination->instanceName, $destination->instanceUrl, $this->key() === 'webhook'), $destination, $idempotencyKey);
        } catch (FetchException|GuzzleException|ConnectionException|RequestException|TelegramFailure $exception) {
            $response = null;
            for ($cause = $exception; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                $failure = $cause instanceof FetchException ? $cause->failure : null;
                if ($failure instanceof FetchFailure && $failure->isPolicyRefusal()) {
                    Log::warning('Notification destination refused.', [
                        'destination_id' => $destination->destinationId, 'channel' => $this->key(),
                        'target_class' => $failure->targetClass, 'target_address' => $failure->targetAddress,
                    ]);

                    return new DeliveryResult(DeliveryState::Failed, 'notifications.destination_refused', diagnosis: __(
                        $failure->targetAddress === null ? 'notifications.destination_refused' : 'notifications.destination_refused_address',
                        ['address' => $failure->targetAddress], locale: $destination->locale,
                    ));
                }
                if ($cause instanceof FetchException) {
                    return $this->failure($destination, null, $cause);
                }
                if ($cause instanceof RequestException) {
                    $response ??= $cause->response;
                }
                if ($cause instanceof GuzzleRequestException && ($providerResponse = $cause->getResponse()) instanceof ResponseInterface) {
                    $response ??= new Response($providerResponse);
                }
            }

            return $this->failure($destination, $response, $exception);
        }
    }

    abstract protected function deliver(RenderedAlert $alert, DestinationConfig $destination, string $idempotencyKey): DeliveryResult;

    public function supportsIdempotencyKey(): bool
    {
        return false;
    }

    /** Every channel HTTP request starts with a fresh guarded, bounded delivery attempt. */
    protected function http(string $destinationUrl): PendingRequest
    {
        return Http::setHandler($this->deliveryHttp->handler($destinationUrl))
            ->withoutRedirecting()->timeout(20)->connectTimeout(5);
    }

    protected function httpClient(string $destinationUrl): Client
    {
        // Telegram accepts Guzzle; Laravel's handler keeps Http::fake as the single external seam.
        $handler = Http::setHandler($this->deliveryHttp->handler($destinationUrl))->buildHandlerStack();

        // Inspect rejection responses outside Telegram's sender, which otherwise owns retries and loses causes.
        return new Client(['handler' => $handler, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 20, 'connect_timeout' => 5]);
    }

    protected function failure(DestinationConfig $destination, ?Response $response = null, ?Throwable $exception = null): DeliveryResult
    {
        $config = $this->configSchema()->validateConfig($destination->values());
        $providerError = $response instanceof Response ? $this->providerError($response, $config->secrets()) : null;
        $exceptionClass = $exception instanceof Throwable ? new ReflectionClass($exception)->getShortName() : null;
        $diagnosis = implode(' — ', array_filter([
            $response instanceof Response ? 'HTTP '.$response->status() : null, $exceptionClass, $providerError,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
        if ($exception instanceof FetchException) {
            $diagnosis = $exception->failure->kind === FetchFailureKind::Dns
                ? __('notifications.destination_dns_failed', locale: $destination->locale)
                : $exception->failure->detail();
        }
        $redact = static fn (?string $text): ?string => $text === null ? null : SecretRedactor::redact($text, $config->secrets());
        Log::warning('Notification transport failed.', [
            'destination_id' => $destination->destinationId, 'channel' => $this->key(),
            'http_status' => $response?->status(), 'exception_class' => $redact($exceptionClass),
            'provider_error' => $redact($providerError), 'diagnosis' => $redact($diagnosis),
        ]);

        return new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: $diagnosis);
    }

    /** @param array<array-key, mixed> $secrets */
    private function providerError(Response $response, #[\SensitiveParameter] array $secrets): ?string
    {
        // Only documented provider fields belong in diagnostics, never raw bodies or request URLs.
        $fields = match ($this->key()) {
            'slack', 'ntfy' => [$response->json('error')],
            'telegram' => [$response->json('description')],
            'pushover' => $response->json('errors'),
            'discord' => [$response->json('code'), $response->json('message')],
            default => [],
        };
        $safe = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_string($field) && ! is_int($field)) {
                continue;
            }
            $value = (string) $field;
            if ($value === '' || str_contains($value, '://')) {
                continue;
            }
            foreach (Arr::flatten($secrets) as $secret) {
                // Check the entire value before cutting so credentials beyond the limit also exclude it.
                if (is_string($secret) && $secret !== '' && stripos($value, $secret) !== false) {
                    continue 2;
                }
            }
            $safe[] = mb_substr($value, 0, 120);
        }

        return $safe === [] ? null : mb_substr(implode('; ', $safe), 0, 120);
    }

    protected function headerSecretField(string $labelKey, bool $required = true): Field
    {
        // Reject controls before Guzzle can include the credential in an invalid-header exception.
        return new Field(FieldType::Secret, [$required ? 'required' : 'nullable', 'regex:/^[\\x09\\x20-\\x7E\\x80-\\xFF]*$/D'], presentation: new FieldPresentation($labelKey));
    }

    /** @param list<string> $rules */
    protected function textField(string $labelKey, bool $secret = false, array $rules = []): Field
    {
        return new Field($secret ? FieldType::Secret : FieldType::String, ['required', ...$rules], presentation: new FieldPresentation($labelKey));
    }
}
