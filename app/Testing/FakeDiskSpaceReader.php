<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\DiskSpaceReader;
use RuntimeException;

/** Test double for the filesystem seam, for deterministic capacity and read-failure proofs. */
final class FakeDiskSpaceReader implements DiskSpaceReader
{
    /** @var array<string, array{free: int, total: int}|RuntimeException> */
    public array $samples = [];

    /** @var array{free: int, total: int}|null answer for a path without its own sample; null keeps unknown paths loud */
    private ?array $everyPath = null;

    /** Every path reports ample free space, so a test that runs the system-issue evaluator never reads the host disk. */
    public static function healthy(): self
    {
        $reader = new self;
        $reader->everyPath = ['free' => 80, 'total' => 100];

        return $reader;
    }

    public function read(string $path): array
    {
        $sample = $this->samples[$path] ?? $this->everyPath ?? throw new \LogicException('No disk-space sample for '.$path);
        if ($sample instanceof RuntimeException) {
            throw $sample;
        }

        return $sample;
    }
}
