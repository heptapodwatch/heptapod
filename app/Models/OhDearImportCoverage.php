<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Import-owned identity retained independently of regenerated recurring windows.
 * @property int $site_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[Table(name: 'ohdear_import_coverages')]
final class OhDearImportCoverage extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
