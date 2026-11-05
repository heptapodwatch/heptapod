<?php

declare(strict_types=1);

namespace Heptapod\Data;

use Heptapod\Enums\SiteStatus;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptLiteral;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptTuple;

/**
 * Emits SiteStatus::severity() and SiteStatus::attention() as exact TypeScript types, so resources/js/lib/status.ts
 * can only compile while its rank table and attention list match PHP.
 */
final readonly class SiteStatusTypes implements TransformedProvider
{
    public function provide(): array
    {
        $statuses = SiteStatus::cases();
        usort($statuses, static fn (SiteStatus $left, SiteStatus $right): int => $left->severity() <=> $right->severity());

        return [
            new Transformed(new TypeScriptAlias('SiteStatusSeverity', new TypeScriptObject(array_map(
                static fn (SiteStatus $status): TypeScriptProperty => new TypeScriptProperty($status->value, new TypeScriptLiteral($status->severity()), isReadonly: true),
                $statuses,
            ))), new CustomReference('site_status', 'severity'), ['Heptapod', 'Enums']),
            new Transformed(new TypeScriptAlias('SiteStatusAttention', new TypeScriptTuple(array_map(
                static fn (SiteStatus $status): TypeScriptLiteral => new TypeScriptLiteral($status->value),
                SiteStatus::attention(),
            ))), new CustomReference('site_status', 'attention'), ['Heptapod', 'Enums']),
        ];
    }
}
