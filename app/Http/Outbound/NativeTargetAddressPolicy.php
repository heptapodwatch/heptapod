<?php

namespace Heptapod\Http\Outbound;

use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\TargetAddressPolicy;

final readonly class NativeTargetAddressPolicy implements TargetAddressPolicy
{
    /** @var list<string> */
    private array $metadataHosts;

    /** @var list<string|false> */
    private array $metadataPacked;

    public function __construct()
    {
        /** @var list<string> $hosts */
        $hosts = config()->array('heptapod.outbound.metadata_targets.hosts');
        /** @var list<string> $addresses */
        $addresses = config()->array('heptapod.outbound.metadata_targets.addresses');
        $this->metadataHosts = $hosts;
        $this->metadataPacked = array_map(inet_pton(...), $addresses);
    }

    public function assertHostAllowed(string $host): void
    {
        if (in_array($host, $this->metadataHosts, true)) {
            throw new FetchException(new FetchFailure(FetchFailureKind::PrivateTarget, targetClass: 'cloud_metadata'));
        }
    }

    public function assertAddressAllowed(string $ip, bool $permitsPrivate): void
    {
        // Native flags do not classify mapped IPv6 by its embedded IPv4 range.
        $packed = inet_pton($ip);
        $validatedIp = $packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff"
            ? inet_ntop(substr($packed, 12)) : $ip;
        // Translation/tunnel prefixes can conceal private IPv4 destinations.
        $translated = $packed !== false && strlen($packed) === 16 && (
            substr($packed, 0, 12) === hex2bin('0064ff9b0000000000000000')
            || substr($packed, 0, 6) === hex2bin('0064ff9b0001')
            || substr($packed, 0, 2) === hex2bin('2002')
        );
        $validatedPacked = is_string($validatedIp) ? inet_pton($validatedIp) : false;
        $linkLocal = $validatedPacked !== false && (
            (strlen($validatedPacked) === 4 && str_starts_with($validatedPacked, "\xa9\xfe"))
            || (strlen($validatedPacked) === 16 && ord($validatedPacked[0]) === 0xFE && (ord($validatedPacked[1]) & 0xC0) === 0x80)
        );
        if ($linkLocal || $translated || in_array($validatedPacked, $this->metadataPacked, true)
            || (! $permitsPrivate && filter_var($validatedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false)) {
            $metadata = $validatedIp === '169.254.169.254' || in_array($validatedPacked, $this->metadataPacked, true);
            $class = $metadata ? 'cloud_metadata' : ($linkLocal ? 'link_local' : ($translated ? 'translation' : 'private_network'));
            throw new FetchException(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: is_string($validatedIp) ? $validatedIp : $ip, targetClass: $class));
        }
    }
}
