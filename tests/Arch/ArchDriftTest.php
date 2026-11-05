<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Tests\Arch\ArchDrift;

// The drift lists may only shrink: an entry whose call or method is gone must be deleted with the fix.
test('every controller dispatch drift entry still matches a call', function (): void {
    foreach (ArchDrift::ControllerDispatch as $entry) {
        $calls = ArchDrift::calls(dirname(__DIR__, 2).'/'.$entry['file'], [$entry['call']], false);
        expect(array_any($calls, static fn (array $call): bool => $call['method'] === $entry['method']))
            ->toBeTrue("Stale arch-drift entry {$entry['file']} {$entry['method']}() {$entry['call']}: remove it from ArchDrift::ControllerDispatch.");
    }
    expect(ArchDrift::ControllerDispatch)->toBeArray();
});

test('every controller write drift entry still matches a call', function (array $entry): void {
    $calls = ArchDrift::calls(dirname(__DIR__, 2).'/'.$entry['file'], [$entry['call']], true);

    expect(array_any($calls, static fn (array $call): bool => $call['method'] === $entry['method']))
        ->toBeTrue("Stale arch-drift entry {$entry['file']} {$entry['method']}() {$entry['call']}: remove it from ArchDrift::ControllerWrites.");
})->with(array_map(static fn (array $entry): array => [$entry], ArchDrift::ControllerWrites));

test('every read snapshot transaction allowance still matches its exact call', function (array $entry): void {
    expect($entry['call'])->toBe('transaction');
    $calls = ArchDrift::calls(dirname(__DIR__, 2).'/'.$entry['file'], ['transaction'], true);
    expect(array_any($calls, static fn (array $call): bool => $call['method'] === $entry['method']))->toBeTrue();
})->with(array_map(static fn (array $entry): array => [$entry], ArchDrift::ReadSnapshotTransactions));

test('every non-resource controller drift entry still declares its public methods', function (): void {
    foreach (ArchDrift::NonResourceControllerMethods as $controller => $methods) {
        $public = array_map(static fn (ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass($controller)->getMethods(ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $method) {
            expect(in_array($method, $public, true))
                ->toBeTrue("Stale arch-drift entry {$controller} {$method}(): remove it from ArchDrift::NonResourceControllerMethods.");
        }
    }
    expect(ArchDrift::NonResourceControllerMethods)->toBeArray();
});

test('every class drift entry still violates the rule it is listed for', function (string $class, string $rule): void {
    $reflection = new ReflectionClass($class);

    expect(match ($rule) {
        'enum outside Enums' => $reflection->isEnum(),
        'throwable outside Exceptions' => $reflection->implementsInterface(Throwable::class),
        'command without suffix' => ! str_ends_with($class, 'Command'),
        'mail class that is not a queued Mailable' => ! $reflection->isSubclassOf(Mailable::class) || ! $reflection->implementsInterface(ShouldQueue::class),
        'notifications class that is not a Notification' => ! $reflection->isSubclassOf(Notification::class),
    })->toBeTrue("Stale arch-drift entry {$class} ({$rule}): remove it from ArchDrift.");
})->with([
    ...array_map(static fn (string $class): array => [$class, 'enum outside Enums'], ArchDrift::EnumsOutsideEnums),
    ...array_map(static fn (string $class): array => [$class, 'throwable outside Exceptions'], ArchDrift::ThrowablesOutsideExceptions),
    ...array_map(static fn (string $class): array => [$class, 'command without suffix'], ArchDrift::CommandsWithoutSuffix),
    ...array_map(static fn (string $class): array => [$class, 'mail class that is not a queued Mailable'], ArchDrift::MailNonMailables),
    ...array_map(static fn (string $class): array => [$class, 'notifications class that is not a Notification'], ArchDrift::NotificationsNonNotifications),
]);
