<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\SiteAddressLookup;
use Illuminate\Support\Facades\DB;

final class DnsSiteAddresses implements SiteAddressLookup
{
    /**
     * One read: each enabled DNS check's newest accepted run (checks.latest_run_id) finds its observation through the
     * (run_id, claim_token) index, so the read grows with the number of DNS checks, not with their history. Addresses
     * compare as binary, so differently written IPv6 forms match.
     */
    public function sitesPointingAt(array $addresses): array
    {
        $wanted = [];
        foreach ($addresses as $address) {
            $binary = self::binary($address);
            if ($binary !== null) {
                $wanted[$binary] = $address;
            }
        }
        if ($wanted === []) {
            return [];
        }
        /** @var list<object{site_id: int, address: string|null}> $records */
        $records = DB::select("SELECT o.site_id, coalesce(r->>'ip', r->>'ipv6') AS address
            FROM checks c JOIN dns_observations o ON o.run_id = c.latest_run_id AND o.check_id = c.id
            CROSS JOIN LATERAL jsonb_array_elements(o.snapshot->'dns_records') r
            WHERE c.type = 'dns' AND c.enabled AND o.run_ended_at IS NOT NULL AND r->>'type' IN ('A', 'AAAA')
            ORDER BY o.site_id");
        $sites = [];
        foreach ($records as $record) {
            $binary = self::binary($record->address);
            if ($binary !== null && isset($wanted[$binary])) {
                $sites[$record->site_id] ??= $wanted[$binary];
            }
        }

        return $sites;
    }

    private static function binary(?string $address): ?string
    {
        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton((string) $address);

        return $binary === false ? null : $binary;
    }
}
