<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AddonEvent extends Data
{
    /** @var array<string, string|int|float|bool|null|list<string|int|float|bool|null>>|null */
    public readonly ?array $presentationParams;

    /**
     * @param  array<string, mixed>  $payload  Versioned evidence without secrets, e.g. changed DNS records.
     * @param  array<array-key, mixed>|null  $presentationParams  Bounded headline facts retained with the accepted run; null means this event has no aggregate headline.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $dedupeKey,
        public readonly int $version,
        public readonly array $payload,
        public readonly ?SuppressionSubject $subject = null,
        ?array $presentationParams = null,
    ) {
        if ($version < 1 || $type === '' || $dedupeKey === '') {
            throw new \InvalidArgumentException('Addon events need a type, dedupe key and positive payload version.');
        }
        $namespace = explode('.', $type, 2)[0];
        $bytes = 2;
        foreach ($presentationParams ?? [] as $key => $value) {
            $context = 'addon='.$namespace.' key='.$key;
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Presentation facts require named keys: '.$context);
            }
            if (is_array($value)) {
                if (! array_is_list($value) || array_any($value, static fn (mixed $item): bool => ! is_scalar($item) && $item !== null)) {
                    throw new \InvalidArgumentException('Presentation facts require scalar values or flat scalar lists: '.$context);
                }
            } elseif (! is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Presentation facts require scalar values or flat scalar lists: '.$context);
            }
            try {
                $encoded = json_encode([$key => $value], JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('Presentation facts must be JSON-serializable: '.$context, $exception->getCode(), previous: $exception);
            }
            $bytes += strlen($encoded) - 2 + ($bytes > 2 ? 1 : 0);
            if ($bytes > 4096) {
                throw new \InvalidArgumentException('Presentation facts exceed 4096 JSON bytes: '.$context);
            }
        }
        /** @var array<string, string|int|float|bool|null|list<string|int|float|bool|null>>|null $presentationParams */
        $this->presentationParams = $presentationParams;
    }
}
