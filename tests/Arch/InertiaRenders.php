<?php

declare(strict_types=1);

namespace Tests\Arch;

use Inertia\Inertia;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\LaravelData\Data;

/** Every Inertia page render in app/, classified by its props argument, for InertiaPageDataTest. */
final class InertiaRenders
{
    /**
     * Calls `Inertia::render(...)` under any import alias or qualified name, and the `inertia(...)` helper.
     *
     * @return list<array{file: string, line: int, component: string, kind: 'page-data'|'raw'|'none'}>
     */
    public static function all(): array
    {
        $root = dirname(__DIR__, 2);
        $renders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file->getPathname())),
                static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $namespace = '';
            $class = null;
            $method = null;
            $imports = [];
            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;
                if (is_array($token) && $token[0] === T_NAMESPACE && is_array($next)) {
                    $namespace = $next[1];
                } elseif (is_array($token) && $token[0] === T_USE && $class === null && is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $alias = ($tokens[$index + 2][0] ?? null) === T_AS ? $tokens[$index + 3][1] : substr((string) strrchr('\\'.$next[1], '\\'), 1);
                    $imports[strtolower($alias)] = $next[1];
                } elseif (is_array($token) && $token[0] === T_CLASS && is_array($next) && $next[0] === T_STRING) {
                    $class = $namespace.'\\'.$next[1];
                } elseif (is_array($token) && $token[0] === T_FUNCTION && is_array($next) && $next[0] === T_STRING) {
                    $method = $next[1];
                }
                $argumentsStart = self::renderArgumentsStart($tokens, $index, $namespace, $imports);
                if ($argumentsStart === null) {
                    continue;
                }
                [$component, $props] = self::arguments($tokens, $argumentsStart);
                $renders[] = ['file' => substr($file->getPathname(), strlen($root) + 1), 'line' => $token[2],
                    'component' => trim(self::text($component), "'"), 'kind' => self::kind($props, $class, $method, $imports)];
            }
        }

        return $renders;
    }

    /**
     * Index after the opening parenthesis when the token at $index starts an Inertia page render, otherwise null.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function renderArgumentsStart(array $tokens, int $index, string $namespace, array $imports): ?int
    {
        $token = $tokens[$index];
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $previous = $tokens[$index - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_USE], true)) {
            return null;
        }
        $name = $token[1];
        if (($tokens[$index + 1] ?? null) === '(' && in_array(strtolower(ltrim($name, '\\')), ['inertia', 'inertia\\inertia'], true)) {
            return $index + 2;
        }
        $resolved = match ($token[0]) {
            T_NAME_FULLY_QUALIFIED => ltrim($name, '\\'),
            T_NAME_QUALIFIED => ($imports[strtolower(strstr($name, '\\', true))] ?? $namespace.'\\'.strstr($name, '\\', true)).strstr($name, '\\'),
            default => $imports[strtolower($name)] ?? $namespace.'\\'.$name,
        };
        $member = $tokens[$index + 2] ?? null;
        if (strcasecmp($resolved, Inertia::class) === 0 && ($tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON
            && is_array($member) && strtolower($member[1]) === 'render' && ($tokens[$index + 3] ?? null) === '(') {
            return $index + 4;
        }

        return null;
    }

    /**
     * Tokens of the first two top-level arguments, starting after the opening parenthesis.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: list<array{0: int, 1: string, 2: int}|string>, 1: list<array{0: int, 1: string, 2: int}|string>}
     */
    private static function arguments(array $tokens, int $start): array
    {
        $arguments = [[]];
        $depth = 0;
        for ($index = $start; ; $index++) {
            $text = self::text([$tokens[$index]]);
            if (in_array($text, ['(', '[', '{'], true) || (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $arguments[] = [];

                continue;
            }
            $arguments[array_key_last($arguments)][] = $tokens[$index];
        }

        return [$arguments[0], $arguments[1] ?? []];
    }

    /**
     * "page-data" only when the WHOLE props expression is `new XPageData(...)` or `$typed->method(...)` returning a
     * *PageData, optionally followed by exactly `->toArray()`. "none" for a page without props; anything else is "raw".
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $props
     * @param  array<string, string>  $imports
     * @return 'page-data'|'raw'|'none'
     */
    private static function kind(array $props, ?string $class, ?string $method, array $imports): string
    {
        if ($props === []) {
            return 'none';
        }
        $pageData = static fn (string $name): bool => str_ends_with($name, 'PageData') && is_subclass_of($name, Data::class);
        $end = null;
        $dataClass = null;
        if (($props[0][0] ?? null) === T_NEW && is_array($props[1] ?? null) && ($props[2] ?? null) === '(') {
            $name = $props[1][1];
            $dataClass = $props[1][0] === T_NAME_FULLY_QUALIFIED ? ltrim($name, '\\') : ($imports[strtolower($name)] ?? null);
            $end = self::closingParenthesis($props, 2);
        } elseif (($props[0][0] ?? null) === T_VARIABLE && ($props[1][0] ?? null) === T_OBJECT_OPERATOR && ($props[2][0] ?? null) === T_STRING
            && ($props[3] ?? null) === '(' && $class !== null && $method !== null) {
            $dataClass = self::returnType($class, $method, substr($props[0][1], 1), $props[2][1]);
            $end = self::closingParenthesis($props, 3);
        }
        if ($end === null || $dataClass === null || ! $pageData($dataClass)) {
            return 'raw';
        }
        $rest = strtolower(self::text(array_slice($props, $end + 1)));

        return in_array($rest, ['', '->toarray()'], true) ? 'page-data' : 'raw';
    }

    /** Declared return class of `$parameter->$call()` inside $class::$method, or null when it cannot be resolved. */
    private static function returnType(string $class, string $method, string $parameter, string $call): ?string
    {
        foreach (new ReflectionMethod($class, $method)->getParameters() as $candidate) {
            $type = $candidate->getType();
            if ($candidate->getName() === $parameter && $type instanceof ReflectionNamedType && method_exists($type->getName(), $call)) {
                $returns = new ReflectionMethod($type->getName(), $call)->getReturnType();

                return $returns instanceof ReflectionNamedType ? $returns->getName() : null;
            }
        }

        return null;
    }

    /**
     * Index of the parenthesis closing the one at $open.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function closingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;
        foreach (array_slice($tokens, $open, null, true) as $index => $token) {
            $depth += match ($token) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<array{0: int, 1: string, 2: int}|string>  $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $tokens));
    }
}
