<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\SiteAddressLookup;
use Illuminate\Support\Facades\DB;
use Pdp\Domain;

final class DnsSiteAddresses implements SiteAddressLookup
{
    /**
     * One read: each enabled DNS check's newest accepted run (checks.latest_run_id) finds its observation through the
     * (run_id, claim_token) index, so the read grows with the number of DNS checks, not with their history. The answer of
     * the site's own name is its `<host>|A` and `<host>|AAAA` group, keyed as DnsCheck keys it. Addresses compare as binary,
     * so two spellings of one address match.
     */
    public function sitesPointingAt(array $addresses, array $exceptSiteIds, int $limit): array
    {
        $wanted = [];
        foreach ($addresses as $address) {
            $binary = $this->binary($address);
            if ($binary !== null) {
                $wanted[$binary] = $address;
            }
        }
        if ($wanted === [] || $limit < 1) {
            return [];
        }
        $except = $exceptSiteIds === [] ? '' : ' AND o.site_id NOT IN ('.implode(',', array_map(intval(...), $exceptSiteIds)).')';
        /** @var list<object{site_id: int, url: string, answer: string, address: string|null}> $records */
        $records = DB::select("SELECT o.site_id, s.url, groups.key AS answer, coalesce(r->>'ip', r->>'ipv6') AS address
            FROM checks c JOIN dns_observations o ON o.run_id = c.latest_run_id AND o.check_id = c.id
            JOIN sites s ON s.id = o.site_id
            CROSS JOIN LATERAL jsonb_each(o.snapshot->'normalized_records') groups
            CROSS JOIN LATERAL jsonb_array_elements(groups.value) r
            WHERE c.type = 'dns' AND c.enabled AND o.run_ended_at IS NOT NULL AND (groups.key LIKE '%|A' OR groups.key LIKE '%|AAAA')".$except);
        /** @var array<int, array{total: int, matched: array<string, true>}> $answers */
        $answers = [];
        $hosts = [];
        foreach ($records as $record) {
            $host = $hosts[$record->site_id] ??= $this->host($record->url);
            if ($record->answer !== $host.'|A' && $record->answer !== $host.'|AAAA') {
                continue;
            }
            $binary = $this->binary($record->address);
            if ($binary === null) {
                continue;
            }
            $answers[$record->site_id] ??= ['total' => 0, 'matched' => []];
            $answers[$record->site_id]['total']++;
            if (isset($wanted[$binary])) {
                $answers[$record->site_id]['matched'][$wanted[$binary]] = true;
            }
        }
        $matches = array_filter($answers, static fn (array $answer): bool => $answer['matched'] !== []);
        // Site id => [the whole answer is on the addresses, the number of matching addresses].
        $strength = array_map(static fn (array $answer): array => [count($answer['matched']) === $answer['total'] ? 1 : 0, count($answer['matched'])], $matches);
        uksort($matches, static fn (int $a, int $b): int => [$strength[$b], $a] <=> [$strength[$a], $b]);

        return array_map(static fn (array $answer): array => array_keys($answer['matched']), array_slice($matches, 0, $limit, true));
    }

    /** The site's host name as DnsCheck queries it (ASCII, no root label); empty for a URL without one. */
    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(Domain::fromIDNA2008($host)->toAscii()->withoutRootLabel()->toString()) : '';
    }

    private function binary(?string $address): ?string
    {
        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton((string) $address);

        return $binary === false ? null : $binary;
    }
}
