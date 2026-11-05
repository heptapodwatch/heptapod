<?php

declare(strict_types=1);

namespace Heptapod\Data;

use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;

/**
 * Page contracts wrap props in ClosureLazy so Inertia evaluates only the keys a partial reload asks for. Such a prop is
 * still sent on every full visit, so it stays required in TypeScript; deferred and optional props stay optional.
 */
final class PageDataTransformer extends DataClassTransformer
{
    protected function classPropertyProcessors(): array
    {
        return [new class implements ClassPropertyProcessor
        {
            public function execute(PhpPropertyNode $phpPropertyNode, mixed $annotation, TypeScriptProperty $property): TypeScriptProperty
            {
                if ($property->type instanceof TypeScriptUnion) {
                    $property->type->types = array_values(array_filter($property->type->types, static fn (mixed $type): bool => ! $type instanceof TypeScriptReference
                        || ! $type->reference instanceof ClassStringReference || $type->reference->classString !== ClosureLazy::class));
                }

                return $property;
            }
        }, ...parent::classPropertyProcessors()];
    }
}
