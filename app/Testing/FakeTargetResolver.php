<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\Http\TargetResolver;

final class FakeTargetResolver implements TargetResolver
{
    /** @var array<string, list<non-empty-list<string>>> */
    private array $answers = [];

    /** @var list<?float> */
    public array $deadlines = [];

    /** @param non-empty-list<string> ...$answers */
    public function respondWith(string $host, array ...$answers): self
    {
        $this->answers[$host] = array_values($answers);

        return $this;
    }

    public function resolve(string $host, ?float $deadline = null): array
    {
        $this->deadlines[] = $deadline;
        if (! isset($this->answers[$host]) || $this->answers[$host] === []) {
            throw new \LogicException('Unexpected target lookup: '.$host);
        }
        $answer = $this->answers[$host][0];
        if (count($this->answers[$host]) > 1) {
            array_shift($this->answers[$host]);
        }

        return $answer;
    }
}
