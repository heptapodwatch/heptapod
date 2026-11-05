<?php

use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldPresentation;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Models\Check;
use Heptapod\Models\NotificationDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

uses(RefreshDatabase::class);

function headerSchema(): Schema
{
    return new Schema([
        'http_client_headers' => new Field(FieldType::List, ['required'], items: new Field(FieldType::Object, properties: [
            'name' => new Field(FieldType::String, ['required']),
            'value' => new Field(FieldType::Secret, ['required'], presentation: new FieldPresentation('checks.header.value')),
        ])),
        'timeout' => new Field(FieldType::Integer, ['required', 'min:1']),
        'secret' => new Field(FieldType::Secret, ['nullable']),
    ]);
}

it('round trips ordered config through Postgres without exposing encrypted secrets in generic output or logs', function (): void {
    $input = ['http_client_headers' => [
        ['name' => 'Authorization', 'value' => 'secret-one'],
        ['name' => 'Authorization', 'value' => 'secret-two'],
    ], 'timeout' => 5, 'secret' => null];
    $validated = headerSchema()->validateConfig($input);
    $check = Check::factory()->create(['config' => $validated])->refresh();
    expect($check->executionConfig())->toEqual($input);
    expect($check->executionConfig()['http_client_headers'])->toBe($input['http_client_headers']);
    $row = DB::table('checks')->find($check->id);
    expect($row->config)->not->toContain('secret-one', 'secret-two');
    expect($row->config_secrets)->not->toContain('secret-one', 'secret-two');
    expect($check->toJson())->not->toContain('secret-one', 'secret-two', 'config_secrets');
    expect(json_encode($validated, JSON_THROW_ON_ERROR))->not->toContain('secret-one', 'secret-two');
    $handler = new TestHandler;
    new Logger('contract', [$handler])->info('check configuration', ['check' => $check, 'config' => $validated]);
    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain('secret-one', 'secret-two');
});

it('rejects invalid config before a check can be persisted', function (array $input, string $errorField): void {
    try {
        Check::factory()->create(['config' => headerSchema()->validateConfig($input)]);
        $this->fail('Invalid config was accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$errorField]);
    }
    expect(Check::query()->count())->toBe(0);
})->with([
    'unknown root' => [['http_client_headers' => [['name' => 'A', 'value' => 'secret']], 'timeout' => 5, 'unknown' => true], 'values'],
    'unknown header' => [['http_client_headers' => [['name' => 'A', 'value' => 'secret', 'unknown' => true]], 'timeout' => 5], 'values.http_client_headers.0'],
    'strict integer' => [['http_client_headers' => [['name' => 'A', 'value' => 'secret']], 'timeout' => '5'], 'values.timeout'],
    'headers must be a list' => [['http_client_headers' => ['named' => ['name' => 'A', 'value' => 'secret']], 'timeout' => 5], 'values.http_client_headers'],
]);

it('persists nested nullable and explicitly open config while enforcing assertion limits', function (): void {
    $schema = new Schema([
        'response_body_assertions' => new Field(FieldType::List, ['nullable', 'max:10'], items: new Field(FieldType::Object, properties: [
            'path' => new Field(FieldType::String, ['required']),
            'operator' => new Field(FieldType::String, ['required', 'in:equals,exists']),
            'value' => new Field(FieldType::String, ['nullable']),
        ])),
        'notification_settings' => new Field(FieldType::Object, additionalProperties: true),
        'enabled' => new Field(FieldType::Boolean),
        'threshold' => new Field(FieldType::Number),
    ]);
    $values = ['response_body_assertions' => null, 'notification_settings' => ['performance' => 70], 'enabled' => true, 'threshold' => 0.5];
    $check = Check::factory()->create(['config' => $schema->validateConfig($values)])->refresh();
    expect($check->executionConfig())->toEqual($values);
    expect(fn () => $check->update(['config' => $schema->validateConfig(['response_body_assertions' => array_fill(0, 11, ['path' => 'ok', 'operator' => 'exists', 'value' => null])])]))->toThrow(ValidationException::class);
    expect($check->fresh()->executionConfig())->toEqual($values);
});

it('preserves empty schema objects in persisted and generic JSON while retaining list shape', function (): void {
    $schema = new Schema([
        'notification_settings' => new Field(FieldType::Object, additionalProperties: true),
        'groups' => new Field(FieldType::List, items: new Field(FieldType::Object, properties: [
            'settings' => new Field(FieldType::Object, additionalProperties: true),
            'names' => new Field(FieldType::List, items: new Field(FieldType::String)),
        ])),
    ]);
    $values = ['notification_settings' => [], 'groups' => [['settings' => [], 'names' => []]]];
    $check = Check::factory()->create(['config' => $schema->validateConfig($values)])->refresh();
    $stored = json_decode(DB::table('checks')->where('id', $check->id)->value('config'), flags: JSON_THROW_ON_ERROR);
    expect($stored->notification_settings)->toBeInstanceOf(stdClass::class);
    expect($stored->groups[0]->settings)->toBeInstanceOf(stdClass::class);
    expect($stored->groups[0]->names)->toBe([]);
    $public = json_decode($check->toJson(), flags: JSON_THROW_ON_ERROR);
    expect($public->config)->toEqual($stored);
    expect($check->executionConfig())->toEqual($values);
});

it('rejects nonempty lists in open object settings before persistence', function (): void {
    $schema = new Schema(['notification_settings' => new Field(FieldType::Object, additionalProperties: true)]);
    try {
        Check::factory()->create(['config' => $schema->validateConfig(['notification_settings' => [70]])]);
        $this->fail('List-shaped object was accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['values.notification_settings']);
    }
    expect(Check::query()->count())->toBe(0);
});

it('does not persist unvalidated config or duplicate scheduling settings', function (): void {
    expect(fn () => Check::factory()->create(['config' => ['secret' => 'plaintext']]))->toThrow(InvalidArgumentException::class);
    $schema = new Schema(['frequency_value' => new Field(FieldType::Integer)]);
    expect(fn () => Check::factory()->create(['config' => $schema->validateConfig(['frequency_value' => 1])]))->toThrow(InvalidArgumentException::class);
    expect(Check::query()->count())->toBe(0);
});

it('rejects short nonempty secrets at every nested schema save boundary', function (string $value): void {
    foreach ([['http_client_headers' => [['name' => 'Authorization', 'value' => $value]], 'timeout' => 5], ['http_client_headers' => [['name' => 'Authorization', 'value' => 'valid-secret']], 'timeout' => 5, 'secret' => $value]] as $input) {
        try {
            Check::factory()->create(['config' => headerSchema()->validateConfig($input)]);
            $this->fail('Short secret was persisted.');
        } catch (ValidationException $exception) {
            $path = array_key_exists('secret', $input) ? 'values.secret' : 'values.http_client_headers.0.value';
            expect(array_keys($exception->errors()))->toBe([$path]);
        }
    }
    expect(Check::query()->count())->toBe(0);
})->with(['a', 'ab', 'abc', '   ', '  ab  ']);

it('clears optional secret values and removes explicitly empty credential headers', function (): void {
    $schema = new Schema([
        'secret' => new Field(FieldType::Secret, ['nullable']),
        'headers' => new Field(FieldType::List, items: new Field(FieldType::Object, properties: [
            'name' => new Field(FieldType::String, ['required']),
            'value' => new Field(FieldType::Secret, ['present']),
        ])),
    ]);
    $check = Check::factory()->create(['config' => $schema->validateConfig(['secret' => '', 'headers' => [['name' => 'Authorization', 'value' => ''], ['name' => 'X-Key', 'value' => 'valid-secret']]])])->refresh();
    expect($check->executionConfig())->toBe(['headers' => [['name' => 'X-Key', 'value' => 'valid-secret']]])
        ->and($check->config_secrets)->toBe(['headers' => [['value' => 'valid-secret']]]);
});

it('validates trimmed secret length without changing the stored bytes', function (): void {
    $check = Check::factory()->create(['config' => headerSchema()->validateConfig(['secret' => ' abcd ', 'http_client_headers' => [['name' => 'Authorization', 'value' => ' abcd ']], 'timeout' => 5])])->refresh();
    expect($check->executionConfig()['secret'])->toBe(' abcd ')
        ->and($check->executionConfig()['http_client_headers'][0]['value'])->toBe(' abcd ');
    $destination = NotificationDestination::factory()->create(['config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://hooks.example/receiver', 'secret' => ' abcd '])]);
    expect($destination->refresh()->destinationConfig()->string('secret'))->toBe(' abcd ');
});

it('publishes declared defaults including null without resetting omitted persisted config', function (): void {
    $schema = new Schema([
        'threshold' => new Field(FieldType::Integer, ['sometimes', 'min:0'], default: 0),
        'keyword' => new Field(FieldType::String, ['sometimes', 'nullable'], default: null),
        'secret' => new Field(FieldType::Secret, ['sometimes']),
    ]);
    $published = json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR)['fields'];
    expect($published['threshold']['default'])->toBe(0)
        ->and($published['keyword'])->toHaveKey('default', null)
        ->and($published['secret'])->not->toHaveKey('default');
    expect($schema->defaults())->toBe(['threshold' => 0, 'keyword' => null]);
    $check = Check::factory()->create(['config' => $schema->validateConfig([])])->refresh();
    expect($check->executionConfig())->toBe([]);
});

it('normalizes nested plain strings before validation without altering secrets or coercing types', function (): void {
    $schema = new Schema(['routes' => new Field(FieldType::List, ['required'], items: new Field(FieldType::Object, properties: [
        'verb' => new Field(FieldType::String, ['required', 'in:POST'], uppercase: true),
        'label' => new Field(FieldType::String, ['required'], uppercase: true),
        'token' => new Field(FieldType::Secret, ['required']),
    ]))]);
    $check = Check::factory()->create(['config' => $schema->validateConfig(['routes' => [['verb' => 'post', 'label' => 'alpha', 'token' => 'MiXeD-Secret']]])])->refresh();
    expect($check->executionConfig()['routes'][0])->toBe(['verb' => 'POST', 'label' => 'ALPHA', 'token' => 'MiXeD-Secret']);
    expect(fn (): ValidatedConfig => $schema->validateConfig(['routes' => [['verb' => 'post', 'label' => 1, 'token' => 'MiXeD-Secret']]]))->toThrow(ValidationException::class);
    expect(fn (): Field => new Field(FieldType::Secret, uppercase: true))->toThrow(InvalidArgumentException::class);
});

it('rejects trimmed string-list normalization for non-string and secret fields', function (FieldType $type, ?FieldType $item): void {
    expect(fn (): Field => new Field($type, items: $item instanceof FieldType ? new Field($item) : null, trimmedStrings: true))
        ->toThrow(InvalidArgumentException::class, 'Only lists of non-secret strings');
})->with([
    'scalar string' => [FieldType::String, null],
    'scalar secret' => [FieldType::Secret, null],
    'secret list' => [FieldType::List, FieldType::Secret],
    'integer list' => [FieldType::List, FieldType::Integer],
]);
