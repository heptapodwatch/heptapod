<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Heptapod\Contracts\OhDearDefinitionImporter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ImportCronDefinitions implements OhDearDefinitionImporter
{
    public function __construct(private CronDefinitions $definitions, private Actions\CreateCronTask $create, private Actions\UpdateCronTask $update) {}

    public function validate(array $definitions): void
    {
        $validator = Validator::make(['definitions' => $definitions], ['definitions' => 'array', 'definitions.*.id' => 'required|integer|min:1|distinct']);
        $errors = $validator->errors()->messages();
        foreach ($definitions as $index => $definition) {
            /** @var array<string, mixed> $values */
            $values = Arr::except($definition, ['id']);
            try {
                $this->definitions->validate($values);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors['definitions.'.$index.'.'.$field] = $messages;
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function __invoke(int $checkId, array $definitions): void
    {
        $this->validate($definitions);
        DB::transaction(function () use ($checkId, $definitions): void {
            DB::table('checks')->where('id', $checkId)->lockForUpdate()->firstOrFail();
            foreach ($definitions as $definition) {
                $task = CronCheck::query()->where('oh_dear_id', $definition['id'])->first();
                /** @var array<string, mixed> $values */
                $values = Arr::except($definition, ['id']);
                if ($task === null) {
                    $task = ($this->create)($checkId, $values);
                } else {
                    if ($task->check_id !== $checkId) {
                        throw new \LogicException('Oh Dear cron identity belongs to another check.');
                    }
                }
                /** @var int $ohDearId */
                $ohDearId = $definition['id'];
                ($this->update)($task, $values, $ohDearId);
            }
        });
    }
}
