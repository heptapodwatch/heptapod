<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /servers/{id}. Server and history are closures, so the page's polls evaluate only what they ask for. */
#[TypeScript]
final class ServerPageData extends Data
{
    /**
     * History: the peak per step over the retention window. Install: the agent comes from the GitHub release of this
     * instance's version, never from the instance.
     *
     * @param  ClosureLazy|array{step_seconds: int, points: list<array{at: string, cpu: float, load: float, memory: float|null, disks: array<string, float>}>}  $history
     * @param  array{endpoint: string, version: string, release_url: string, is_release: bool}  $install
     */
    public function __construct(
        public readonly ClosureLazy|ServerRowData $server,
        public readonly ServerThresholdsData $thresholds,
        public readonly ClosureLazy|array $history,
        public readonly array $install,
    ) {}
}
