<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Illuminate\Support\Facades\Validator;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Symfony\Component\HttpFoundation\IpUtils;

#[TypeScript]
final class DnsSnapshot extends CheckDetailData
{
    /**
     * @param  list<array<string, int|string>>  $dns_records
     * @param  array<string, list<string>>  $raw_dns_records
     * @param  array<string, list<array<string, int|string>>>  $normalized_records
     * @param  list<array{type: string, host: string, record_type: string|null, nameserver?: string, reason?: string}>  $issues
     * @param  list<string>  $authoritative_nameservers
     * @param  list<array{type: string, host: string, reason: string}>  $diagnostics
     */
    public function __construct(
        public readonly array $dns_records,
        public readonly array $raw_dns_records,
        public readonly array $normalized_records,
        public readonly array $issues,
        public readonly array $authoritative_nameservers,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct(CheckKey::Dns);
    }

    /**
     * TTL and answer ordering are deliberately excluded from record identity.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, int|string>>
     */
    public static function normalize(array $records): array
    {
        if (count($records) > 1000) {
            throw new \UnexpectedValueException('DNS answer exceeds 1000 records.');
        }
        $set = [];
        foreach ($records as $record) {
            /** @var array{host: string, ttl: int, class: string, type: string} $common */
            $common = Validator::make($record, [
                'host' => ['required', 'string', 'max:253'], 'ttl' => ['required', 'integer:strict', 'min:0'],
                'class' => ['required', 'string', 'in:IN'], 'type' => ['required', 'string', 'in:'.implode(',', DnsCheck::Types)],
            ])->validate();
            $fields = match ($common['type']) {
                'A' => ['ip' => ['required', 'ipv4']],
                'AAAA' => ['ipv6' => ['required', 'ipv6']],
                'CNAME', 'NS' => ['target' => ['required', 'string', 'max:253']],
                'MX' => ['target' => ['present', 'string', 'max:253'], 'pri' => ['required', 'integer:strict', 'min:0', 'max:65535']],
                'TXT' => ['txt' => ['present', 'string', 'max:65535']],
                'CAA' => ['flags' => ['required', 'integer:strict', 'min:0', 'max:255'], 'tag' => ['required', 'string'], 'value' => ['present', 'string', 'max:65535']],
                default => throw new \UnexpectedValueException('Unsupported DNS record.'),
            };
            /** @var array<string, int|string> $data */
            $data = Validator::make($record, $fields)->validate();
            unset($common['ttl']);
            $common['host'] = strtolower(rtrim($common['host'], '.'));
            if (isset($data['target']) && is_string($data['target'])) {
                $data['target'] = strtolower(rtrim($data['target'], '.'));
            }
            if (isset($data['ipv6']) && is_string($data['ipv6'])) {
                $packed = inet_pton($data['ipv6']);
                if ($packed === false) {
                    throw new \UnexpectedValueException('Invalid IPv6 DNS record.');
                }
                $data['ipv6'] = strtolower((string) inet_ntop($packed));
            }
            $normalized = [...$common, ...$data];
            $set[json_encode($normalized, JSON_THROW_ON_ERROR)] = $normalized;
        }
        ksort($set);

        return array_values($set);
    }

    /**
     * @param  array<string, list<array<string, int|string>>>  $previous
     * @return array<string, array{added: list<array<string, int|string>>, removed: list<array<string, int|string>>}>
     */
    public function changes(array $previous, bool $ignoreProxyRotation = true): array
    {
        $changes = [];
        foreach ($this->normalized_records as $key => $current) {
            // A newly configured hostname/type establishes its own first baseline.
            if (! array_key_exists($key, $previous)) {
                continue;
            }
            $old = $previous[$key];
            $type = explode('|', $key)[1];
            $rotationHosts = $ignoreProxyRotation ? $this->cloudflareRotationHosts($old, $current, $type) : [];
            $oldIds = array_fill_keys(array_map(self::comparisonIdentity(...), $old), true);
            $newIds = array_fill_keys(array_map(self::comparisonIdentity(...), $current), true);
            $added = array_values(array_filter($current, static fn (array $record): bool => ! isset($rotationHosts[$record['host']]) && ! isset($oldIds[self::comparisonIdentity($record)])));
            $removed = array_values(array_filter($old, static fn (array $record): bool => ! isset($rotationHosts[$record['host']]) && ! isset($newIds[self::comparisonIdentity($record)])));
            if ($added !== [] || $removed !== []) {
                $changes[$type] ??= ['added' => [], 'removed' => []];
                $changes[$type]['added'] = [...$changes[$type]['added'], ...array_map($this->eventRecord(...), $added)];
                $changes[$type]['removed'] = [...$changes[$type]['removed'], ...array_map($this->eventRecord(...), $removed)];
            }
        }
        ksort($changes);

        return $changes;
    }

    /**
     * Only address rotation within Cloudflare is equivalent; missing or mixed sets are changes.
     * Group by the actual record host because a reply can also contain alias target addresses.
     *
     * @param  list<array<string, int|string>>  $old
     * @param  list<array<string, int|string>>  $current
     * @return array<string, true>
     */
    private function cloudflareRotationHosts(array $old, array $current, string $type): array
    {
        $field = match ($type) {
            'A' => 'ip', 'AAAA' => 'ipv6', default => null,
        };
        if ($field === null || $old === [] || $current === []) {
            return [];
        }
        /** @var list<string> $ranges */
        $ranges = config()->array('dns.cloudflare_proxy_ranges');
        $oldHosts = $currentHosts = [];
        foreach ($old as $record) {
            $oldHosts[(string) $record['host']][] = (string) $record[$field];
        }
        foreach ($current as $record) {
            $currentHosts[(string) $record['host']][] = (string) $record[$field];
        }
        $rotations = [];
        foreach ($oldHosts as $host => $addresses) {
            if (isset($currentHosts[$host]) && array_all([...$addresses, ...$currentHosts[$host]], static fn (string $address): bool => IpUtils::checkIp($address, $ranges))) {
                $rotations[$host] = true;
            }
        }

        return $rotations;
    }

    /** @param array<string, int|string> $record
     * @return array<string, int|string>
     */
    private function eventRecord(array $record): array
    {
        if (isset($record['txt']) && is_string($record['txt']) && mb_strlen($record['txt']) > 255) {
            $record['txt'] = mb_substr($record['txt'], 0, 252).'...';
        }

        return $record;
    }

    /** @param array<array-key, mixed> $value */
    public static function comparisonIdentity(array $value): string
    {
        // Postgres JSONB reorders object keys; DNS lists retain their normalized order.
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::comparisonIdentity($child);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function bounded(): self
    {
        $raw = [];
        $remaining = 16000;
        foreach ($this->raw_dns_records as $type => $lines) {
            foreach ($lines as $line) {
                if ($remaining <= 0) {
                    break 2;
                }
                $raw[$type][] = mb_substr($line, 0, $remaining);
                $remaining -= mb_strlen(array_last($raw[$type]));
            }
        }

        return new self(array_slice($this->dns_records, 0, 200), $raw, [], array_slice($this->issues, 0, 100), array_slice($this->authoritative_nameservers, 0, 100), array_slice($this->diagnostics, 0, 100));
    }
}
