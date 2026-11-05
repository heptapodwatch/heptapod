<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CronTaskStore
{
    public function find(int $id): CronTaskData;

    /** @return list<CronTaskData> */
    public function list(int $checkId): array;

    /**
     * @param  list<int>  $checkIds
     * @return list<CronTaskData> Ordered by task id, as in list().
     */
    public function listForChecks(array $checkIds): array;

    /** @param array<string, mixed> $values */
    public function create(int $checkId, array $values): CronTaskData;

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): CronTaskData;

    public function delete(int $id): void;

    /** @param array{runtime?: int|float, exit_code?: int|string, memory?: int|float, failure_message?: string|null} $values */
    public function recordPing(string $uuid, string $phase, array $values): void;

    public function rotate(int $id): CronTaskData;

    /** @param list<array<string, mixed>> $definitions
     * @return list<CronTaskData>
     */
    public function sync(int $checkId, array $definitions): array;
}
