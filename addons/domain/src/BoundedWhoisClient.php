<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Klkvsk\Whoeasy\Client\Exception\ClientTimeoutException;
use Klkvsk\Whoeasy\Client\Whois\WhoisClient;

final class BoundedWhoisClient extends WhoisClient
{
    public function __construct(private readonly TargetResolver $resolver, private readonly TargetAddressPolicy $policy)
    {
        parent::__construct();
    }

    public function query(string $server, string $query, int $timeout = 15, ?string $queryFormat = null, ?string $proxyUri = null): string
    {
        if ($proxyUri !== null || str_contains($server, '://')) {
            throw new ExternalNetworkException('WHOIS requires a direct TCP registry destination.');
        }
        $literal = trim($server, '[]');
        $address = filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$literal.']:43' : (str_contains($server, ':') ? $server : $server.':43');
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $endpoint = parse_url('tcp://'.$address);
        if (! is_array($endpoint) || ! isset($endpoint['host'], $endpoint['port']) || isset($endpoint['user'], $endpoint['pass'])
            || array_diff(array_keys($endpoint), ['scheme', 'host', 'port']) !== []) {
            throw new ExternalNetworkException('WHOIS requires a valid TCP registry destination.');
        }
        $host = TargetHost::normalize($endpoint['host']);
        $this->policy->assertHostAllowed($host);
        try {
            $addresses = $this->resolver->resolve($host, microtime(true) + $timeout);
        } catch (TargetResolutionException $exception) {
            throw new ExternalNetworkException('WHOIS target resolution failed.', $exception->getCode(), previous: $exception);
        } catch (FetchException $exception) {
            if ($exception->failure->kind !== FetchFailureKind::Timeout) {
                throw $exception;
            }
            throw new ClientTimeoutException('WHOIS target resolution timed out.', $exception->getCode(), previous: $exception);
        }
        foreach ($addresses as $ip) {
            $this->policy->assertAddressAllowed($ip, false);
        }
        $socket = false;
        $code = 0;
        foreach ($addresses as $ip) {
            $address = (str_contains($ip, ':') ? '['.$ip.']' : $ip).':'.$endpoint['port'];
            $connectSeconds = ($deadline - hrtime(true)) / 1_000_000_000;
            if ($connectSeconds <= 0) {
                throw new ClientTimeoutException('WHOIS registry connection timed out.');
            }
            // Keep native address failover while every attempted destination remains prevalidated.
            $socket = @stream_socket_client('tcp://'.$address, $code, $message, $connectSeconds);
            if ($socket !== false) {
                break;
            }
        }
        if ($socket === false) {
            throw new ExternalNetworkException('WHOIS registry connection failed.', (int) $code);
        }
        try {
            $request = ($queryFormat === null ? $query : sprintf($queryFormat, $query))."\r\n";
            if (fwrite($socket, $request) !== strlen($request)) {
                throw new ExternalNetworkException('WHOIS query could not be written.');
            }
            if (! stream_set_blocking($socket, false)) {
                throw new ExternalNetworkException('WHOIS registry could not use nonblocking reads.');
            }
            $raw = '';
            while (! feof($socket)) {
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw new ClientTimeoutException('WHOIS registry read timed out.');
                }
                $read = [$socket];
                $write = $except = [];
                $ready = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000));
                if ($ready === false) {
                    throw new ExternalNetworkException('WHOIS registry readiness check failed.');
                }
                if ($ready === 0 || hrtime(true) >= $deadline) {
                    throw new ClientTimeoutException('WHOIS registry read timed out.');
                }
                $chunk = fread($socket, max(1, min(8192, 262145 - strlen($raw))));
                if ($chunk === false) {
                    throw new ExternalNetworkException('WHOIS registry read failed.');
                }
                $raw .= $chunk;
                if (strlen($raw) > 262144) {
                    throw new ExternalNetworkException('WHOIS response exceeds 256 KiB.');
                }
            }
            if (! mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
                if (strlen($raw) > 262144) {
                    throw new ExternalNetworkException('WHOIS decoded response exceeds 256 KiB.');
                }
            }

            return $raw;
        } finally {
            fclose($socket);
        }
    }
}
