<?php

declare(strict_types=1);

namespace Heptapod\Data;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckRegistry;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\PhpClassReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIntersection;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptLiteral;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNever;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;

final readonly class CheckDetailTransformer implements Transformer
{
    public function __construct(private CheckRegistry $registry) {}

    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        $history = $phpClassNode->getName() === CheckHistoryData::class;
        if (! $history && $phpClassNode->getName() !== CheckDetailData::class) {
            return Untransformable::create();
        }
        $types = [];
        foreach ($this->registry->keys() as $key) {
            $type = $this->registry->get($key);
            if ($history) {
                if (! $type instanceof CheckHistoryProvider) {
                    continue;
                }
                $returnType = new \ReflectionMethod($type, 'history')->getReturnType();
                $class = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : null;
            } else {
                $class = $type->definition()->detailDataClass;
            }
            if ($class !== null) {
                $types[] = new TypeScriptIntersection([
                    TypeScriptReference::referencingPhpClass($class),
                    new TypeScriptObject([new TypeScriptProperty('check_type', new TypeScriptLiteral($key->value), isReadonly: true)]),
                ]);
            }
        }

        return new Transformed(
            new TypeScriptAlias($context->name, $types === [] ? new TypeScriptNever : new TypeScriptUnion($types)),
            new PhpClassReference($phpClassNode), explode('\\', $phpClassNode->getNamespaceName()),
        );
    }
}
