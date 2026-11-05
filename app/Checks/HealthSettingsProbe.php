<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\HealthProbeEvaluator;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Support\SecretMerge;
use Heptapod\Support\SecretReentry;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class HealthSettingsProbe
{
    public function __construct(private CheckRegistry $registry, private SiteProbe $probe) {}

    /** @param array<string, mixed> $settings
     * @return array{result: 'ok'|'unauthorized'|'invalid_format'|'unreachable', component_count: int|null}
     */
    public function __invoke(Check $check, User $actor, #[\SensitiveParameter] array $settings): array
    {
        $type = $this->registry->get($check->type);
        if (! $type instanceof HealthProbeEvaluator) {
            throw new \LogicException('Health checks must provide a probe evaluator.');
        }
        $schema = $type->definition()->configSchema;
        $previous = $check->executionConfig();
        $supplied = $settings;
        if (($settings['secret'] ?? null) === '') {
            $settings['secret'] = null;
        }
        foreach ($schema->fields as $name => $field) {
            if (array_key_exists($name, $settings)) {
                $settings[$name] = SecretMerge::preserve($field, $settings[$name], $previous[$name] ?? null, $name);
            }
        }
        /** @var array{result_url: string, secret: string, headers?: list<array{name: string, value: string}>} $config */
        $config = array_replace($previous, $settings);
        $schema->validateConfig($config);
        $storedUrl = $previous['result_url'] ?? null;
        if ($storedUrl !== null) {
            if (! is_string($storedUrl)) {
                throw new \LogicException('Stored health endpoint URL must be a string.');
            }
            if (SecretReentry::origin($storedUrl) !== SecretReentry::origin($config['result_url'])) {
                $errors = SecretReentry::errors($check->config_secrets ?? [], $supplied, $check->type->value.'_check_settings');
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }
        $parts = parse_url($config['result_url']);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['result_url' => __('api.url_credentials_unsupported')]);
        }
        $headers = ['oh-dear-health-check-secret' => [$config['secret']]];
        foreach ($config['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])][] = $header['value'];
        }
        $attempts = RateLimiter::hit('health-check-probe:'.$actor->id, 60);
        abort_if($attempts > 6, 429);
        $response = $this->probe->fetch($config['result_url'], $headers);
        if ($response->failure instanceof FetchFailure || $response->status === null) {
            return ['result' => 'unreachable', 'component_count' => null];
        }
        if (in_array($response->status, [401, 403], true)) {
            return ['result' => 'unauthorized', 'component_count' => null];
        }
        if ($response->status >= 300) {
            return ['result' => 'unreachable', 'component_count' => null];
        }
        $count = $type->probeComponentCount($response->body, CarbonImmutable::now('UTC'));

        return $count === null ? ['result' => 'invalid_format', 'component_count' => null] : ['result' => 'ok', 'component_count' => $count];
    }
}
