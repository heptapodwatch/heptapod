<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckRunPresentationPartData extends Data
{
    /** Engine summary string params that are controlled values in every part: `date` is an engine instant, `resolution_key` a catalog key. */
    public const array SummaryTrusted = ['date', 'resolution_key'];

    /** Named data fields for engine summary params that carry run-error or target text; addon parts built from summaries inherit them. */
    public const array SummaryUntrusted = ['message' => 'error_message', 'error' => 'error_message', 'address' => 'target_address', 'host' => 'target_host', 'reason' => 'http_reason_phrase'];

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @param  array<string, string>  $labels  Translation keys for named parameters, not target-supplied text.
     * @param  array<string, string>  $untrusted  Website, run-error or user text params, mapped to the named data field that carries them
     *                                            outside controlled headlines (for example `names` => `failing_tasks`).
     * @param  list<string>  $trusted  String params the presenter controls (enums, instants). Any other string param is untrusted;
     *                                 an undeclared one keeps its parameter name as its data field.
     * @param  array<string, list<string>>  $lists  Presenter-controlled enum lists, joined in the recipient's locale, never target text.
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly array $labels = [],
        public readonly ?string $shortKey = null,
        public readonly array $untrusted = [],
        public readonly array $trusted = [],
        public readonly array $lists = [],
    ) {}

    /** @param array<string, string|int|float|bool|null> $params */
    public function withParams(array $params): self
    {
        return new self($this->key, $params, $this->labels, $this->shortKey, $this->untrusted, $this->trusted, $this->lists);
    }

    /** @return array<string, string|int|float|bool|null> */
    public function localizedParams(string $locale): array
    {
        $params = SummaryParameters::localizedMeasurements($this->params, $locale);
        $lists = $this->lists;
        // Older retained presentation parameters used comma-separated DNS types.
        if (str_starts_with($this->key, 'dns.') && is_string($params['types'] ?? null) && ! isset($lists['types'])) {
            $lists['types'] = explode(', ', $params['types']);
        }
        foreach ($lists as $name => $values) {
            if ($name !== 'types' || ! str_starts_with($this->key, 'dns.')) {
                throw new \LogicException('Presentation list has no localized formatter.');
            }
            $last = array_pop($values);
            $params[$name] = $values === [] ? ($last ?? '') : implode(__('dns.record_types.separator', locale: $locale), $values)
                .__('dns.record_types.last_separator', locale: $locale).$last;
        }

        return $params;
    }

    /** @return array<string, string> Untrusted param name => named data field. */
    public function untrustedFields(): array
    {
        $fields = [];
        foreach ($this->params as $name => $value) {
            if (is_string($value) && ! in_array($name, [...$this->trusted, ...self::SummaryTrusted], true)) {
                $fields[$name] = $this->untrusted[$name] ?? self::SummaryUntrusted[$name] ?? $name;
            }
        }

        return $fields;
    }
}
