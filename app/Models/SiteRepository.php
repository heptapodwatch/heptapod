<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * A link from a site to one of its source repositories. Links only: Heptapod never signs in to the Git host.
 *
 * @property int $id
 * @property int $site_id
 * @property string $url the canonical form of RepositoryUrl
 * @property string|null $label
 * @property int $position
 */
#[Guarded(['id'])]
final class SiteRepository extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
