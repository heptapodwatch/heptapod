<?php

namespace Heptapod\Contracts\Http;

/** First-resolution trust is shared by every HTTP dispatch in one check execution. */
final class TargetAddressScope
{
    /** @var array<string, non-empty-list<string>> */
    private array $addresses = [];

    /**
     * Resolve each host once, then reuse those numeric destinations for the execution.
     * A DNS change cannot redirect a later connection to a new private address.
     *
     * @return array{addresses: non-empty-list<string>, dnsSeconds: float}
     */
    public function resolve(string $host, TargetResolver $resolver, ?float $deadline): array
    {
        if (isset($this->addresses[$host])) {
            return ['addresses' => $this->addresses[$host], 'dnsSeconds' => 0.0];
        }
        if ($resolver instanceof TimedTargetResolver) {
            $resolved = $resolver->resolveTimed($host, $deadline);
            $addresses = $resolved->addresses;
            $dnsSeconds = $resolved->dnsSeconds;
        } else {
            $started = hrtime(true);
            $addresses = $resolver->resolve($host, $deadline);
            $dnsSeconds = (hrtime(true) - $started) / 1_000_000_000;
        }
        $this->addresses[$host] = $addresses;

        return ['addresses' => $addresses, 'dnsSeconds' => $dnsSeconds];
    }

    /**
     * @param  non-empty-list<string>  $resolved
     * @return non-empty-list<string>
     */
    public function initialAddresses(string $host, array $resolved): array
    {
        return $this->addresses[$host] ??= $resolved;
    }
}
