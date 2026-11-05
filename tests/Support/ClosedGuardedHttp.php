<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\TargetAddressScope;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Http\Outbound\NativePrivateTargetGuard;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Heptapod\Testing\FakeTargetResolver;

final class ClosedGuardedHttp
{
    /**
     * Keep production classification and pinning, but make even a broken guard unable to open a socket.
     *
     * @param  array<string, list<non-empty-list<string>>|\Throwable>  $answers
     * @param  Closure(TargetResolver): void  $operation
     */
    public static function run(array $answers, MockHandler|Closure $transport, Closure $operation): void
    {
        $resolver = new class($answers) implements TargetResolver
        {
            private readonly FakeTargetResolver $fake;

            /** @var array<string, list<non-empty-list<string>>> */
            public array $used = [];

            /** @var list<string> */
            public array $lookups = [];

            /** @param array<string, list<non-empty-list<string>>|\Throwable> $answers */
            public function __construct(private array $answers)
            {
                $this->fake = new FakeTargetResolver;
                foreach ($answers as $host => $sequence) {
                    if (is_array($sequence)) {
                        $this->fake->respondWith((string) $host, ...$sequence);
                    }
                }
            }

            public function resolve(string $host, ?float $deadline = null): array
            {
                $this->lookups[] = $host;
                $answer = $this->answers[$host] ?? null;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                $resolved = $this->fake->resolve($host, $deadline);
                $this->used[$host][] = $resolved;

                return $resolved;
            }
        };
        $nativeCalls = 0;
        $denyNative = static function () use (&$nativeCalls): never {
            $nativeCalls++;
            throw new \LogicException('Closed HTTP case attempted native resolution.');
        };
        $abstracts = [NativeTargetResolver::class, DnsResolver::class, TargetResolver::class, PrivateTargetGuard::class];
        $bindings = app()->getBindings();
        $instances = [];
        foreach ($abstracts as $abstract) {
            if (app()->isShared($abstract)) {
                $instances[$abstract] = app($abstract);
            }
        }
        try {
            app()->bind(NativeTargetResolver::class, $denyNative);
            app()->bind(DnsResolver::class, $denyNative);
            app()->instance(TargetResolver::class, $resolver);
            $production = app(NativePrivateTargetGuard::class);
            $guard = new class($production, $transport) implements PrivateTargetGuard
            {
                /** @var list<HandlerStack> */
                public array $stacks = [];

                public function __construct(private readonly NativePrivateTargetGuard $production, private readonly MockHandler|Closure $transport) {}

                public function clientConfig(?string $siteHost, bool $publicTargetsOnly = false, ?float $deadline = null, ?float $selectTimeoutSeconds = null, ?TargetAddressScope $scope = null): array
                {
                    $config = $this->production->clientConfig($siteHost, $publicTargetsOnly, $deadline, $selectTimeoutSeconds, $scope);
                    $stack = $config['handler'];
                    if (! $stack instanceof HandlerStack) {
                        throw new \LogicException('Expected production HTTP middleware stack.');
                    }
                    $stack->setHandler($this->transport);
                    $this->stacks[] = $stack;

                    return $config;
                }
            };
            app()->instance(PrivateTargetGuard::class, $guard);
            $operation($resolver);
            expect($nativeCalls)->toBe(0);
            expect($guard->stacks)->not->toBeEmpty();
            foreach ($guard->stacks as $stack) {
                expect(new \ReflectionProperty(HandlerStack::class, 'handler')->getValue($stack))->toBe($transport);
            }
            $expected = [];
            foreach ($answers as $host => $sequence) {
                if (is_array($sequence)) {
                    $expected[$host] = array_values(array_unique(array_merge(...$sequence)));
                    sort($expected[$host]);
                }
            }
            $used = [];
            foreach ($resolver->used as $host => $sequence) {
                $used[$host] = array_values(array_unique(array_merge(...$sequence)));
                sort($used[$host]);
            }
            ksort($expected);
            ksort($used);
            expect($used)->toBe($expected);
        } finally {
            foreach ($abstracts as $abstract) {
                app()->offsetUnset($abstract);
                if (isset($bindings[$abstract])) {
                    app()->bind($abstract, $bindings[$abstract]['concrete'], $bindings[$abstract]['shared']);
                }
                if (isset($instances[$abstract])) {
                    app()->instance($abstract, $instances[$abstract]);
                }
            }
        }
    }
}
