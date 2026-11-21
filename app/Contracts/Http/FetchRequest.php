<?php

namespace Heptapod\Contracts\Http;

final readonly class FetchRequest
{
    public string $url;

    /**
     * Limits count response entity bytes, before and after content decoding.
     * Untrusted targets use the guard; siteHost is the operator's trusted host.
     *
     * @param  array<array-key, string|non-empty-array<string>>  $headers
     * @param  ?list<int>  $acceptedStatuses  Null accepts 2xx; explicit statuses replace that default.
     * @param  bool  $refusalIsFinding  The caller reports a policy refusal of this discovered target (a crawled link or a
     *                                  sitemap entry) as a per-item finding, so the refusal does not become a Check error.
     */
    public function __construct(
        string $url,
        public string $method = 'GET',
        #[\SensitiveParameter] public array $headers = [],
        #[\SensitiveParameter] public ?string $body = null,
        public int $maxWireBytes = 1048576,
        public int $maxDecodedBytes = 1048576,
        public RedirectPolicy $redirects = RedirectPolicy::Off,
        public int $maxRedirects = 5,
        public ?string $siteHost = null,
        public ?float $timeoutSeconds = null,
        public ?array $acceptedStatuses = null,
        public bool $strictRedirects = true,
        public bool $withCertificateInfo = false,
        public bool $publicTargetsOnly = false,
        public bool $refusalIsFinding = false,
    ) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($parts === false || ! in_array($scheme, ['http', 'https'], true) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Outbound HTTP requires an absolute HTTP(S) URL without credentials.');
        }
        $this->url = $scheme.substr($url, strlen($scheme));
        if ($maxWireBytes < 1 || $maxDecodedBytes < 1 || $maxRedirects < 0 || ($timeoutSeconds !== null && (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0))) {
            throw new \InvalidArgumentException('Invalid outbound HTTP limits.');
        }
        $this->validateAcceptedStatuses($acceptedStatuses);
        if ($redirects === RedirectPolicy::Untrusted && $siteHost === null && ! $publicTargetsOnly) {
            throw new \InvalidArgumentException('Untrusted HTTP requires the operator site host.');
        }
        if ($publicTargetsOnly && ($redirects !== RedirectPolicy::Untrusted || $siteHost !== null)) {
            throw new \InvalidArgumentException('Public-only HTTP cannot trust an operator site host.');
        }
        if ($refusalIsFinding && $redirects !== RedirectPolicy::Untrusted) {
            throw new \InvalidArgumentException('Only untrusted discovered targets can report a policy refusal as a finding.');
        }
    }

    /** @param ?array<mixed> $statuses PHP validates array type, not element types. */
    private function validateAcceptedStatuses(?array $statuses): void
    {
        foreach ($statuses ?? [] as $status) {
            if (! is_int($status) || $status < 100 || $status > 599) {
                throw new \InvalidArgumentException('Invalid accepted HTTP status.');
            }
        }
    }

    public function acceptsStatus(int $status): bool
    {
        return $this->acceptedStatuses === null ? $status >= 200 && $status < 300 : in_array($status, $this->acceptedStatuses, true);
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
