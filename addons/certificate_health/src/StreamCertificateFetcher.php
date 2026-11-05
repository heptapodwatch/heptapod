<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\TlsFailureReason;
use Illuminate\Support\Facades\Config;

final readonly class StreamCertificateFetcher implements CertificateFetcher
{
    public function __construct(private TargetResolver $resolver, private TargetAddressPolicy $policy) {}

    public function fetch(string $hostname, int $port = 443, ?string $siteHost = null): CertificateFetchResult
    {
        if ($hostname === '' || preg_match('/[\s\x00\/]/', $hostname) === 1 || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid TLS endpoint.');
        }
        $deadline = hrtime(true) + 10_000_000_000;
        $host = TargetHost::normalize($hostname);
        $this->policy->assertHostAllowed($host);
        try {
            $addresses = $this->resolver->resolve($host, microtime(true) + $this->remaining($deadline));
        } catch (TargetResolutionException $exception) {
            throw new ExternalNetworkException('TLS target resolution failed.', $exception->getCode(), previous: $exception);
        } catch (FetchException $exception) {
            if ($exception->failure->kind !== FetchFailureKind::Timeout) {
                throw $exception;
            }
            throw new ExternalTimeoutException('TLS target resolution timed out.', $exception->getCode(), previous: $exception);
        }
        $permitsPrivate = $siteHost !== null && $host === TargetHost::normalize($siteHost);
        foreach ($addresses as $address) {
            $this->policy->assertAddressAllowed($address, $permitsPrivate);
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => trim($hostname, '[]'), 'SNI_enabled' => true];
        // An operator PEM bundle adds roots; it must not replace the system trust directory.
        $ssl['capath'] = openssl_get_cert_locations()['default_cert_dir'];
        $caFile = Config::get('certificate_health.ca_file');
        if ($caFile !== null) {
            if (! is_string($caFile) || ! is_file($caFile) || ! is_readable($caFile)) {
                throw new \InvalidArgumentException('Certificate trust bundle is unreadable.');
            }
            $ssl['cafile'] = $caFile;
        }
        $connected = $this->connectFirst($addresses, $port, stream_context_create(['ssl' => $ssl]), $deadline);
        $socket = $connected['socket'];
        $address = $connected['address'];
        try {
            $this->setTimeout($socket, $deadline);
            // OpenSSL rejection is an invalid observation, not a transport exception. No own trust/name rules.
            $nativeErrors = [];
            set_error_handler(static function (int $severity, string $message) use (&$nativeErrors): bool {
                $nativeErrors[] = $message;

                return true;
            });
            try {
                $verified = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;
            } finally {
                restore_error_handler();
            }
            $failureReason = $verified ? null : TlsFailureReason::fromNativeError(implode('\n', $nativeErrors));
            if (stream_get_meta_data($socket)['timed_out']) {
                throw new ExternalTimeoutException('TLS verification timed out.');
            }
        } finally {
            fclose($socket);
        }
        // This second handshake is display-only, including for certificates rejected above.
        $capture = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'capture_peer_cert_chain' => true,
            'verify_peer' => false, 'verify_peer_name' => false,
            'peer_name' => trim($hostname, '[]'), 'SNI_enabled' => true,
        ]]);
        $socket = $this->connect('ssl', $address, $port, $capture, $deadline);
        try {
            /** @var array{ssl: array{peer_certificate_chain?: list<\OpenSSLCertificate>}} $options */
            $options = stream_context_get_options($socket);
            $chain = [];
            // Bounds constrain display work, not the native verification result. The fake uses the same limit in the check.
            foreach (array_slice($options['ssl']['peer_certificate_chain'] ?? [], 0, 20) as $certificate) {
                $pem = '';
                if (! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
                    throw new ExternalNetworkException('TLS certificate export failed.');
                }
                if (strlen($pem) > 65536) {
                    break;
                }
                $chain[] = $pem;
            }

            /** @var array<string, mixed>|false $leaf */
            $leaf = isset($chain[0]) ? openssl_x509_parse($chain[0]) : false;
            if (! $verified && is_array($leaf) && $chain !== []) {
                $failureReason = TlsFailureReason::forRejectedCertificate($leaf, $failureReason ?? TlsFailureReason::Handshake,
                    $this->chainTrusted($chain, $ssl), time());
            }

            return new CertificateFetchResult($chain, $hostname, $port, $verified, $failureReason);
        } finally {
            fclose($socket);
        }
    }

    /** @param non-empty-list<string> $chain
     * @param  array<string, mixed>  $ssl
     */
    private function chainTrusted(array $chain, array $ssl): bool
    {
        $roots = [$ssl['capath']];
        if (isset($ssl['cafile'])) {
            $roots[] = $ssl['cafile'];
        }
        $intermediates = null;
        try {
            if (count($chain) > 1) {
                $intermediates = tempnam(sys_get_temp_dir(), 'heptapod-tls-');
                if ($intermediates === false || file_put_contents($intermediates, implode("\n", array_slice($chain, 1))) === false) {
                    throw new \RuntimeException('Unable to prepare TLS chain diagnostics.');
                }
            }
            $trusted = openssl_x509_checkpurpose($chain[0], X509_PURPOSE_SSL_SERVER, $roots, $intermediates);
            if ($trusted === -1) {
                throw new \RuntimeException('Unable to inspect TLS chain trust.');
            }

            return $trusted === true || $trusted === 1;
        } finally {
            if (is_string($intermediates) && ! unlink($intermediates)) {
                throw new \RuntimeException('Unable to remove TLS chain diagnostics.');
            }
        }
    }

    /** Try approved addresses in native order, then pin both handshakes to the successful peer.
     * @param  non-empty-list<string>  $addresses
     * @param  resource  $context
     * @return array{socket: resource, address: string}
     */
    private function connectFirst(array $addresses, int $port, mixed $context, int $deadline): array
    {
        foreach ($addresses as $position => $ip) {
            $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            try {
                return ['socket' => $this->connect('tcp', $address, $port, $context, $deadline), 'address' => $address];
            } catch (ExternalNetworkException $exception) {
                if ($position === array_key_last($addresses)) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('TLS resolution returned no addresses.');
    }

    /** @param resource $context
     * @return resource
     */
    private function connect(string $transport, string $hostname, int $port, mixed $context, int $deadline): mixed
    {
        $seconds = $this->remaining($deadline);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $socket = stream_socket_client("{$transport}://{$hostname}:{$port}", $code, $message, $seconds, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                throw new \RuntimeException((string) $message, (int) $code);
            }

            return $socket;
        } catch (\ErrorException|\RuntimeException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
                throw new ExternalTimeoutException('TLS connection timed out.', $exception->getCode(), previous: $exception);
            }
            throw new ExternalNetworkException('TLS connection failed.', $exception->getCode(), previous: $exception);
        } finally {
            restore_error_handler();
        }
    }

    /** @param resource $socket */
    private function setTimeout(mixed $socket, int $deadline): void
    {
        $seconds = $this->remaining($deadline);
        if (! stream_set_timeout($socket, (int) $seconds, (int) (($seconds - (int) $seconds) * 1_000_000))) {
            throw new \RuntimeException('Unable to set TLS timeout.');
        }
    }

    private function remaining(int $deadline): float
    {
        $seconds = ($deadline - hrtime(true)) / 1_000_000_000;
        if ($seconds <= 0) {
            throw new ExternalTimeoutException('TLS connection timed out.');
        }

        return $seconds;
    }
}
