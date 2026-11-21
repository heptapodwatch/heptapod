<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\TargetResolution;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TimedTargetResolver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class NativeTargetResolver implements TimedTargetResolver
{
    public function resolve(string $host, ?float $deadline = null): array
    {
        return $this->resolveTimed($host, $deadline)->addresses;
    }

    public function resolveTimed(string $host, ?float $deadline = null): TargetResolution
    {
        if ($deadline !== null) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new FetchException(new FetchFailure(FetchFailureKind::Timeout));
            }
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return new TargetResolution([$host], 0.0);
            }
            // The parent must never block inside libc getaddrinfo beyond the HTTP budget.
            // A separate PHP process preserves NSS (/etc/hosts and DNS) and is killed at the deadline.
            // Embedded SAPIs do not identify the PHP CLI through PHP_BINARY.
            $php = (new PhpExecutableFinder)->find(false);
            if ($php === false || ! is_file($php) || ! is_executable($php)) {
                throw new \RuntimeException('Target resolver requires an executable PHP CLI binary.');
            }
            // The child only needs NSS; loading Composer adds unrelated startup work to every lookup.
            $process = new Process([$php, '-r', 'require $argv[1]."/app/Contracts/Http/TargetResolver.php"; require $argv[1]."/app/Contracts/EngineFailure.php"; require $argv[1]."/app/Contracts/Http/TargetResolutionException.php"; require $argv[1]."/app/Contracts/Http/TargetResolution.php"; require $argv[1]."/app/Contracts/Http/TimedTargetResolver.php"; require $argv[1]."/app/Http/Outbound/NativeTargetResolver.php"; try { echo json_encode((new \\Heptapod\\Http\\Outbound\\NativeTargetResolver)->resolveTimed($argv[2]), JSON_THROW_ON_ERROR); } catch (\\Heptapod\\Contracts\\Http\\TargetResolutionException) { exit(2); }', dirname(__DIR__, 3), $host]);
            $process->setTimeout($remaining);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new FetchException(new FetchFailure(FetchFailureKind::Timeout));
            }
            if ($process->getExitCode() === 2) {
                throw new TargetResolutionException('Target DNS lookup failed.');
            }
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Target resolver process failed: '.$process->getErrorOutput());
            }
            /** @var array{addresses: non-empty-list<string>, dnsSeconds: float} $resolved */
            $resolved = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (microtime(true) >= $deadline) {
                throw new FetchException(new FetchFailure(FetchFailureKind::Timeout));
            }

            return new TargetResolution($resolved['addresses'], $resolved['dnsSeconds']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return new TargetResolution([$host], 0.0);
        }
        // getaddrinfo honours system NSS (/etc/hosts as well as DNS), unlike dns_get_record.
        $started = hrtime(true);
        $records = socket_addrinfo_lookup($host, null, ['ai_socktype' => SOCK_STREAM]);
        $dnsSeconds = (hrtime(true) - $started) / 1_000_000_000;
        if ($records === false) {
            throw new TargetResolutionException('Target DNS lookup failed.');
        }
        $ips = [];
        foreach ($records as $record) {
            /** @var array{sin_addr?: string, sin6_addr?: string} $address */
            $address = socket_addrinfo_explain($record)['ai_addr'];
            $ip = $address['sin_addr'] ?? $address['sin6_addr'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            throw new TargetResolutionException('Target lookup returned no addresses.');
        }

        return new TargetResolution(array_values(array_unique($ips)), $dnsSeconds);
    }
}
