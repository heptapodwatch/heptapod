<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

/** The agent's bearer token (ingest contract v1): a fixed prefix, the server's public id and a secret; only the secret's hash is stored. */
final readonly class ServerToken
{
    private function __construct(public string $publicId, private string $secret) {}

    public static function issue(string $publicId): self
    {
        return new self($publicId, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /** Null for anything that is not a well-formed token. */
    public static function parse(?string $bearer): ?self
    {
        if ($bearer === null || preg_match('/^hpa_([0-9a-z]{26})_([A-Za-z0-9_-]{43})$/D', $bearer, $parts) !== 1) {
            return null;
        }

        return new self($parts[1], $parts[2]);
    }

    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    public function matches(string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash());
    }

    public function plainText(): string
    {
        return 'hpa_'.$this->publicId.'_'.$this->secret;
    }
}
