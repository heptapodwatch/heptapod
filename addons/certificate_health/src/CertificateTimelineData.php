<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CertificateTimelineData extends CheckHistoryData
{
    /** @param list<array{run_id: int, observed_at: string, fingerprint: string|null, issuer: string|null, valid_from: string|null, valid_until: string|null, changed: bool|null, count: int, first_observed_at: string, names: list<string>, history_label?: CheckRunPresentationPartData|null}> $observations */
    public function __construct(public readonly array $observations)
    {
        parent::__construct(CheckKey::CertificateHealth);
    }
}
