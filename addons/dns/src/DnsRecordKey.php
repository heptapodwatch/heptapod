<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Pdp\Domain;

/** How DnsCheck names what it queries, and the key of each answer in a snapshot's normalized_records. */
final class DnsRecordKey
{
    /** A host name as DnsCheck queries it: ASCII, without the root label. */
    public static function name(string $host): string
    {
        return Domain::fromIDNA2008($host)->toAscii()->withoutRootLabel()->toString();
    }

    /** The key of one queried host's answer of one record type: `<name>|<type>`. */
    public static function of(string $host, string $type): string
    {
        return self::name($host).'|'.$type;
    }
}
