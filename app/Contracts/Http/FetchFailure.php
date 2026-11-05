<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

use Heptapod\Contracts\TlsFailureReason;

final readonly class FetchFailure
{
    /** Header positions are zero-based; names are escaped and bounded, never values.
     * @param  array{key: string, params: array<string, string>}|null  $tlsSummary
     */
    public function __construct(
        public FetchFailureKind $kind,
        public ?int $status = null,
        public ?string $exceptionClass = null,
        public ?string $headerName = null,
        public ?int $headerPosition = null,
        public ?int $headerValuePosition = null,
        public ?TlsFailureReason $tlsReason = null,
        public ?array $tlsSummary = null,
        /** Failing native transfer's HTTPS origin only; never a path, query, credential or fragment. */
        public ?string $tlsTarget = null,
        public ?string $targetAddress = null,
        /** Policy refusal reason; credentials and schemes retain private_target for API compatibility. */
        public ?string $targetClass = null,
        public bool $redirected = false,
        public ?ConnectionFailureReason $connectionReason = null,
    ) {
        if ($connectionReason instanceof ConnectionFailureReason && $kind !== FetchFailureKind::Connect) {
            throw new \InvalidArgumentException('Connection diagnostics require a connect failure.');
        }
        if ($targetAddress !== null && filter_var($targetAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Policy diagnostic address must be a numeric IP.');
        }
        if ($targetClass !== null && ! in_array($targetClass, ['private_network', 'cloud_metadata', 'link_local', 'translation', 'changed_private', 'credentials', 'unsupported_scheme'], true)) {
            throw new \InvalidArgumentException('Invalid policy target class.');
        }
        if ($tlsTarget !== null) {
            $target = parse_url($tlsTarget);
            if ($kind !== FetchFailureKind::Tls || ! is_array($target) || ($target['scheme'] ?? null) !== 'https'
                || ! isset($target['host']) || $target['host'] === ''
                || isset($target['port']) && $target['port'] < 1
                || array_diff(array_keys($target), ['scheme', 'host', 'port']) !== []
                || filter_var($tlsTarget, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('TLS diagnostic target must be an HTTPS origin on a TLS failure.');
            }
        }
    }

    public function isPolicyRefusal(): bool
    {
        return in_array($this->kind, [FetchFailureKind::PrivateTarget, FetchFailureKind::RedirectBlocked], true);
    }

    /** @return array{key: string, params: array<string, string>} */
    public function policySummary(): array
    {
        if ($this->kind === FetchFailureKind::RedirectBlocked) {
            return ['key' => 'checks.policy.redirect_blocked', 'params' => []];
        }
        $class = $this->targetClass ?? 'private_network';
        if ($class === 'changed_private') {
            return ['key' => 'checks.policy.changed_private', 'params' => []];
        }
        $key = 'checks.policy.'.($this->redirected ? 'redirect_' : '').$class.($this->targetAddress === null ? '' : '_address');

        return ['key' => $key, 'params' => $this->targetAddress === null ? [] : ['address' => $this->targetAddress]];
    }

    /**
     * Catalog cause for presentation; detail() stays the English diagnostic for logs and the run error.
     *
     * @return array{key: string, params: array<string, int>}
     */
    public function summary(): array
    {
        return $this->kind === FetchFailureKind::HttpStatus && $this->status !== null
            ? ['key' => 'causes.outbound.http_status_with_status', 'params' => ['status' => $this->status]]
            : ['key' => 'causes.outbound.'.$this->kind->value, 'params' => []];
    }

    /** Fixed diagnostics deliberately omit vendor messages, URLs and response bodies. EngineMessage maps retained ones. */
    public function detail(): string
    {
        return 'Outbound HTTP failed: '.$this->kind->value.($this->status === null ? '' : ' (HTTP '.$this->status.')').'.';
    }
}
