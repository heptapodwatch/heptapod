<?php

declare(strict_types=1);

use Heptapod\Addons\Crawl\CrawlStartRedirectOffSite;
use Heptapod\Addons\Crawl\CrawlStartRedirectOriginChanged;
use Heptapod\Addons\Crawl\CrawlTargetUnavailable;
use Heptapod\Addons\Dns\DnsResolverStatusException;
use Heptapod\Addons\Lighthouse\LighthouseAuditFailed;
use Heptapod\Addons\Lighthouse\LighthouseBusyException;
use Heptapod\Addons\Lighthouse\LighthouseScopeReleaseFailed;
use Heptapod\Addons\Lighthouse\LighthouseServiceUnavailable;
use Heptapod\Addons\Sitemap\SitemapSizeExceeded;
use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Contracts\EngineFailure;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunCancelled;
use Heptapod\Contracts\StaleEvaluation;
use Heptapod\Contracts\TargetAvailabilityException;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Exceptions\IconRefreshFailed;
use Heptapod\Exceptions\InvalidOhDearRecurringResponse;
use Heptapod\Exceptions\OwnerAlreadyExists;
use Heptapod\Exceptions\UndeclaredImportNotice;
use Heptapod\Import\ImportFailure;
use Heptapod\Support\Api\ApiProblem;
use Heptapod\SystemHealth\DiskReadException;

// Engine messages are a catalog. Every exception class in app/ and addons/*/src is either an EngineFailure, whose every
// possible message must map through EngineMessage::summary(), or declared below as carrying no engine text.
require __DIR__.'/../vendor/autoload.php';
$root = $argv[1] ?? dirname(__DIR__);
$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    fwrite(STDERR, $message.PHP_EOL);
    $failures++;
};

/**
 * Tokens of the first constructor argument at every `new <short>` in a source (whitespace and comments dropped).
 * A `new <short>` without arguments yields an empty argument.
 *
 * @return list<list<array{0: int, 1: string, 2: int}|string>>
 */
$messageArguments = static function (string $source, string $short): array {
    $tokens = array_values(array_filter(token_get_all($source), static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $arguments = [];
    foreach ($tokens as $index => $token) {
        $name = $tokens[$index + 1] ?? null;
        if (! is_array($token) || $token[0] !== T_NEW || ! is_array($name)
            || ! in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || ltrim(strrchr('\\'.$name[1], '\\') ?: '', '\\') !== $short) {
            continue;
        }
        $argument = [];
        if (($tokens[$index + 2] ?? null) === '(') {
            $depth = 0;
            for ($cursor = $index + 3; isset($tokens[$cursor]); $cursor++) {
                $part = $tokens[$cursor];
                if ($depth === 0 && ($part === ',' || $part === ')')) {
                    break;
                }
                $depth += in_array($part, ['(', '[', '{'], true) ? 1 : (in_array($part, [')', ']', '}'], true) ? -1 : 0);
                $argument[] = $part;
            }
        }
        $arguments[] = $argument;
    }

    return $arguments;
};

/** Exception classes whose message is not engine text, with the reason. */
$notEngineText = [
    LighthouseAuditFailed::class => 'caught inside LighthouseCheck; recorded as a catalog cause with its untrusted message',
    LighthouseServiceUnavailable::class => 'caught inside LighthouseCheck; recorded as a catalog service cause',
    LighthouseScopeReleaseFailed::class => 'caught inside LighthouseCheck; recorded as a catalog service cause',
    CheckConfigurationValidationException::class => 'validation messages about user configuration',
    ReclassifiedCheckError::class => 'never thrown; a CheckError class tag whose run summary is a catalog key',
    RunCancelled::class => 'run control flow; no run is recorded',
    StaleEvaluation::class => 'run control flow; no run is recorded',
    ManualRunRefused::class => 'API refusal of a run request, not a run result',
    IconRefreshFailed::class => 'carries a site-icon catalog result, not a run',
    InvalidOhDearRecurringResponse::class => 'Oh Dear import boundary, not a run',
    OwnerAlreadyExists::class => 'console command error, not a run',
    ImportFailure::class => 'offline backup import boundary, not a run',
    UndeclaredImportNotice::class => 'Oh Dear import developer error (an undeclared notice code), not a run',
    ApiProblem::class => 'HTTP problem response, not a run',
    SitemapSizeExceeded::class => 'caught inside SitemapCheck; recorded as a catalog issue',
    CrawlStartRedirectOffSite::class => 'caught inside CrawlEvaluation; recorded as a catalog cause with its untrusted target',
    CrawlStartRedirectOriginChanged::class => 'caught inside CrawlEvaluation; recorded as a catalog cause with its untrusted target',
    DiskReadException::class => 'system-health filesystem read, not a run; UI and mail show its typed reason',
];

$all = [...array_keys(iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)), '/\.php$/')))];
foreach (glob($root.'/addons/*/src', GLOB_ONLYDIR) ?: [] as $addon) {
    $all = [...$all, ...array_keys(iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addon, FilesystemIterator::SKIP_DOTS)), '/\.php$/')))];
}
$sources = [];
$exceptions = [];
foreach ($all as $file) {
    $source = file_get_contents($file) ?: throw new RuntimeException('Could not read '.$file);
    $sources[$file] = $source;
    if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) === 1
        && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) === 1) {
        $name = $namespace[1].'\\'.$class[1];
        if (class_exists($name) && is_subclass_of($name, Throwable::class)) {
            $exceptions[] = $name;
        }
    }
}

// Exceptions that compose their own message from a closed set: every possible message, built through the real constructor.
$samples = [
    FetchException::class => [...array_map(static fn (FetchFailureKind $kind): FetchException => new FetchException(new FetchFailure($kind)), FetchFailureKind::cases()),
        new FetchException(new FetchFailure(FetchFailureKind::HttpStatus, 503))],
    TargetAvailabilityException::class => [...array_map(static fn (FetchFailureKind $kind): TargetAvailabilityException => new TargetAvailabilityException(new FetchFailure($kind)), FetchFailureKind::cases()),
        new TargetAvailabilityException(new FetchFailure(FetchFailureKind::HttpStatus, 503))],
    // CheckedDig clamps the status to these values.
    DnsResolverStatusException::class => array_map(static fn (string $status): DnsResolverStatusException => new DnsResolverStatusException($status), ['SERVFAIL', 'REFUSED', 'FORMERR', 'NOTIMP', 'an error status']),
    LighthouseBusyException::class => [new LighthouseBusyException],
    CrawlTargetUnavailable::class => array_map(static fn (FetchFailureKind $kind): CrawlTargetUnavailable => new CrawlTargetUnavailable(new FetchException(new FetchFailure($kind))), FetchFailureKind::cases()),
];

$raised = 0;
foreach ($exceptions as $exception) {
    if (! is_subclass_of($exception, EngineFailure::class)) {
        if (! array_key_exists($exception, $notEngineText)) {
            $fail('Exception class is neither an EngineFailure nor declared as carrying no engine text: '.$exception);
        }

        continue;
    }
    $constructor = new ReflectionClass($exception)->getConstructor();
    if ($constructor instanceof ReflectionMethod && $constructor->getDeclaringClass()->getFileName() !== false
        && str_starts_with($constructor->getDeclaringClass()->getName(), 'Heptapod\\')) {
        // The class builds its own message.
        foreach ($samples[$exception] ?? [$fail('EngineFailure with its own constructor needs every message as a sample: '.$exception)] as $sample) {
            if ($sample instanceof Throwable) {
                $raised++;
                if (EngineMessage::summary($sample->getMessage()) === null) {
                    $fail('Engine message has no catalog entry: '.$exception.' "'.$sample->getMessage().'"');
                }
            }
        }

        continue;
    }
    // The message is the first constructor argument at every call site. The WHOLE argument must be one string literal
    // (no concatenation, variable or call) and that literal must be cataloged.
    $short = new ReflectionClass($exception)->getShortName();
    foreach ($sources as $file => $source) {
        foreach ($messageArguments($source, $short) as $argument) {
            $raised++;
            $literal = count($argument) === 1 && is_array($argument[0]) && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING
                && str_starts_with($argument[0][1], "'") ? stripslashes(substr($argument[0][1], 1, -1)) : null;
            $text = implode('', array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $argument));
            if ($literal === null) {
                $fail('Engine message must be one cataloged string literal: '.$file.' new '.$short.'('.$text.')');
            } elseif (EngineMessage::summary($literal) === null) {
                $fail('Engine message has no catalog entry: '.$file.' "'.$literal.'"');
            }
        }
    }
}

$keys = [...array_values(EngineMessage::Fixed), ...array_values(EngineMessage::Classes), EngineMessage::ConfigInvalid];
foreach (FetchFailureKind::cases() as $kind) {
    $keys[] = 'causes.outbound.'.$kind->value;
}
$keys = [...$keys, 'causes.outbound.http_status_with_status', 'causes.timeout'];
foreach (['en', 'de'] as $locale) {
    $catalog = [];
    foreach ([...glob($root.'/lang/'.$locale.'/*.json') ?: [], ...glob($root.'/addons/*/lang/'.$locale.'.json') ?: []] as $path) {
        $catalog += json_decode(file_get_contents($path) ?: throw new RuntimeException('Could not read '.$path), true, flags: JSON_THROW_ON_ERROR);
    }
    foreach (array_unique($keys) as $key) {
        if (! is_string($catalog[$key] ?? null) || trim($catalog[$key]) === '') {
            $fail('Missing engine message translation: locale='.$locale.' key='.$key);
        }
    }
}

echo 'Engine messages: '.count($exceptions).' exception classes, '.$raised.' raised messages and '.count(array_unique($keys)).' catalog keys checked; '.$failures.' problems.'.PHP_EOL;
exit($failures === 0 ? 0 : 1);
