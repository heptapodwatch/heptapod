<?php

declare(strict_types=1);

// Proves that every Oh Dear import reason the pinned spec (and the recorded real account) can produce has a
// declared label in Support\OhDearImportNotices, and that every declared label exists in EN and DE.

use Heptapod\Actions\ImportOhDearMonitor;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\OhDearImportMapping;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    fwrite(STDERR, $message.PHP_EOL);
    $failures++;
};

/** @var array<string, mixed> $spec */
$spec = Yaml::parseFile($root.'/tests/Fixtures/OhDear/ohdear-openapi.yml');
$schemas = $spec['components']['schemas'];
$resolve = static function (array $schema) use ($schemas): array {
    if (isset($schema['$ref'])) {
        $schema = $schemas[basename($schema['$ref'])];
    }
    foreach ($schema['anyOf'] ?? $schema['oneOf'] ?? [] as $option) {
        if (isset($option['$ref'])) {
            $schema = $schemas[basename($option['$ref'])];
        }
    }

    return $schema;
};
$fields = [];
foreach ($schemas['Monitor']['properties'] as $property => $schema) {
    if (str_ends_with($property, '_check_settings')) {
        $fields[substr($property, 0, -strlen('_check_settings'))] = array_keys($resolve($schema)['properties'] ?? []);
    }
}
$real = json_decode((string) file_get_contents($root.'/tests/Fixtures/ohdear/real/monitors.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($real['data'] as $monitor) {
    foreach ($monitor as $property => $values) {
        if (str_ends_with($property, '_check_settings') && is_array($values)) {
            $type = substr($property, 0, -strlen('_check_settings'));
            $fields[$type] = array_values(array_unique([...$fields[$type] ?? [], ...array_keys($values)]));
        }
    }
    foreach ($monitor['checks'] as $check) {
        if ($check['type'] === 'broken_links') {
            $fields['broken_links'] = array_values(array_unique([...$fields['broken_links'], ...array_diff(array_keys($check['settings'] ?? []), ['crawler_headers'])]));
        }
    }
}

$codes = ['site:exists', 'plan:missing', 'plan:changed', 'monitor:unsupported', 'report', 'domain:unsupported', 'cron:disabled_without_definitions', 'setting:broken_links.whitelisted_urls'];
$registry = app(CheckRegistry::class);
foreach (array_unique([...$schemas['CheckType']['enum'], ...array_keys($fields)]) as $type) {
    // Addons are optional per install, so every source check type needs an "unavailable" label.
    $codes[] = 'check:'.$type;
    $key = CheckKey::tryFrom($type);
    $mapping = $key === null ? null : $registry->importMapping($key);
    if (! $mapping instanceof OhDearImportMapping) {
        continue;
    }
    foreach (array_diff($fields[$type] ?? [], $mapping->settings) as $field) {
        if (! in_array($type.'_check_settings.'.$field, OhDearImportNotices::Derived, true)) {
            $codes[] = 'setting:'.$type.'.'.$field;
        }
    }
}
// setup:/invalid:/short_secret: are emitted per settings block the engine reads (ImportOhDearMonitor::SettingsTypes)
// and for cron definitions; derive them from each installed addon's config schema.
$hasSecret = static function (array $fields) use (&$hasSecret): bool {
    foreach ($fields as $field) {
        if ($field->type === FieldType::Secret || $hasSecret($field->properties) || ($field->items !== null && $hasSecret([$field->items]))) {
            return true;
        }
    }

    return false;
};
foreach (ImportOhDearMonitor::SettingsTypes as $type) {
    $key = CheckKey::tryFrom($type);
    if ($key === null || ! $registry->importMapping($key) instanceof OhDearImportMapping) {
        continue;
    }
    $codes[] = 'invalid:'.$type;
    $fields = $registry->get($key)->definition()->configSchema->fields;
    if (array_filter($fields, static fn (Field $field): bool => in_array('required', $field->rules, true)) !== []) {
        $codes[] = 'setup:'.$type;
    }
    if ($hasSecret($fields)) {
        $codes[] = 'short_secret:'.$type;
    }
}
if ($registry->importMapping(CheckKey::Cron)?->definitions !== null) {
    array_push($codes, 'invalid:cron', 'setup:cron');
}

foreach ($codes as $code) {
    if (! isset(OhDearImportNotices::Keys[$code])) {
        $fail('Undeclared Oh Dear import notice: '.$code);
    }
}

$keys = array_unique([...array_values(OhDearImportNotices::Keys), OhDearImportNotices::Undeclared, 'import.notice.failed']);
foreach (['en', 'de'] as $locale) {
    $catalog = json_decode((string) file_get_contents($root."/lang/{$locale}/import.json"), true, flags: JSON_THROW_ON_ERROR);
    foreach ($keys as $key) {
        if (! is_string($catalog[$key] ?? null) || $catalog[$key] === '' || $catalog[$key] === $key) {
            $fail("Missing {$locale} import notice label: {$key}");
        }
    }
}

if ($failures > 0) {
    exit(1);
}
echo 'Import notices: '.count(array_unique($codes)).' reachable codes declared; '.count($keys).' labels in EN and DE.'.PHP_EOL;
