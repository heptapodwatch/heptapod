<?php

declare(strict_types=1);

namespace Heptapod\Support\Api;

use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\SummaryKey;

/**
 * Agent-facing summary projection. Only numbers and true/false values count as trusted template parameters;
 * every string parameter can carry website, run-error or user text and is returned as named data, never rendered.
 */
final class TrustedSummary
{
    /** @return array{template: string|null, params: object, untrusted_params: object} */
    public static function project(?string $template, mixed $params): array
    {
        if (! is_array($params)) {
            throw new \LogicException('Summary parameters must be an object.');
        }
        $trusted = [];
        $untrusted = [];
        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                throw new \LogicException('Summary parameter names must be strings.');
            }
            if (is_string($value)) {
                $untrusted[$key] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $trusted[$key] = $value;
            } else {
                throw new \LogicException('Summary parameters must be scalar.');
            }
        }

        // Retained engine text is our catalog cause (integer params only), not remote data.
        $engine = $template === null ? null : EngineMessage::fromSummary($template, [...$trusted, ...$untrusted]);
        if ($engine !== null) {
            ['key' => $template, 'params' => $trusted] = $engine;
            $untrusted = [];
        }

        // Engines write dotted message keys; anything else is legacy free text and stays data.
        if ($template !== null && SummaryKey::isFreeText($template)) {
            $untrusted['summary_text'] = $template;
            $template = null;
        }

        return ['template' => $template, 'params' => (object) $trusted, 'untrusted_params' => (object) $untrusted];
    }
}
