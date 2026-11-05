<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Spatie\Dns\Handlers\Dig;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Support\Factory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class CheckedDig extends Dig
{
    /** @var list<string> */
    public array $rawLines = [];

    public function __construct(Factory $factory, private readonly bool $requireAuthoritative = false)
    {
        parent::__construct($factory);
    }

    /** @return list<Record> */
    public function __invoke(string $domain, int $flag, string $type): array
    {
        // Upstream dig hides response status, turning SERVFAIL into an empty successful answer.
        /** @var array<string> $command */
        $command = [...$this->buildCommand($domain, $type), '+comments', '+noauthority'];
        $process = new Process($command, timeout: 5);
        // Disable automatic buffering; the callback bounds decoded dig text before retaining it.
        $process->disableOutput();
        $output = '';
        $bytes = 0;
        $overflow = false;
        try {
            $exit = $process->run(static function (string $channel, string $chunk) use (&$output, &$bytes, &$overflow): void {
                if ($overflow) {
                    return;
                }
                $bytes += strlen($chunk);
                if ($bytes > 131072) {
                    $overflow = true;
                    throw new ExternalNetworkException('DNS resolver output exceeded 128 KiB.', previous: new \LengthException('DNS process output limit exceeded.'));
                }
                if ($channel === Process::OUT) {
                    $output .= $chunk;
                }
            });
        } catch (ProcessTimedOutException $exception) {
            // Process exceptions include the command/output; only their kind and code are diagnostic.
            throw new ExternalTimeoutException('DNS query timed out.', $exception->getCode(), previous: new \RuntimeException($exception::class, $exception->getCode()));
        } finally {
            $process->stop(0);
        }
        if ($exit !== 0) {
            $cause = new \RuntimeException('DNS process exited with status '.$exit.'.');
            if ($exit === 9) {
                throw new ExternalTimeoutException('DNS resolver did not respond.', previous: $cause);
            }
            throw new ExternalNetworkException('DNS query failed.', previous: $cause);
        }
        if (preg_match('/status: ([A-Z]+),/', $output, $match) !== 1) {
            throw new ExternalNetworkException('DNS response status is missing.', previous: new \UnexpectedValueException('DNS response has no valid status.'));
        }
        if (preg_match('/;; flags:[^;]*\btc\b/', $output) === 1) {
            throw new ExternalNetworkException('DNS response was truncated.', previous: new \UnexpectedValueException('DNS response has its truncation flag set.'));
        }
        if (! in_array($match[1], ['NOERROR', 'NXDOMAIN'], true)) {
            $status = in_array($match[1], ['SERVFAIL', 'REFUSED', 'FORMERR', 'NOTIMP'], true) ? $match[1] : 'an error status';
            throw new DnsResolverStatusException($status);
        }
        // Referrals and recursive replies are not observations from this zone's authority.
        if ($this->requireAuthoritative && preg_match('/;; flags:[^;]*\baa\b/', $output) !== 1) {
            throw new ExternalNetworkException('DNS response is not authoritative.', previous: new \UnexpectedValueException('DNS response has no authoritative-answer flag.'));
        }
        $records = [];
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            try {
                $record = $this->factory->guess($line);
            } catch (\Throwable $exception) {
                // Parser messages can contain record data; the exception kind still identifies the failure.
                throw new ExternalNetworkException('Malformed DNS answer.', $exception->getCode(), previous: new \UnexpectedValueException($exception::class, $exception->getCode()));
            }
            if (! $record instanceof Record) {
                throw new ExternalNetworkException('Malformed DNS answer.', previous: new \UnexpectedValueException('DNS parser returned no record.'));
            }
            // A query for a type may also include the CNAME chain. Its own query owns that set.
            if ($record->type() === $type) {
                $records[] = $record;
                $this->rawLines[] = $line;
            }
        }

        return $records;
    }
}
