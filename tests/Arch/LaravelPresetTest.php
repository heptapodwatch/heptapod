<?php

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\ExecutionMode;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunCancelled;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\StaleEvaluation;
use Heptapod\Contracts\SuppressionComponent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\ServiceProvider;
use Tests\Arch\ArchDrift;

// Faithful namespace-adapted port of Pest 5's ArchPresets/Laravel.php.
const ResourceControllerMethods = ['__construct', '__invoke', 'index', 'show', 'create', 'store', 'edit', 'update', 'destroy', 'middleware'];

pest()->presets()->custom('heptapodLaravel', fn (): array => [
    expect('Heptapod\Traits')
        ->toBeTraits(),

    expect('Heptapod\Concerns')
        ->toBeTraits(),

    // Addons need these wire/schema enums in Contracts, not the core-only Enums namespace.
    expect('Heptapod')
        ->not->toBeEnums()
        ->ignoring([...ArchDrift::EnumsOutsideEnums, 'Heptapod\Enums', CheckKey::class, CheckResult::class, RunResult::class, RunPhase::class, ExecutionMode::class, FieldType::class, DomainRegistrationSource::class, SuppressionComponent::class, AlertSeverity::class, DeliveryState::class, FetchFailureKind::class, AutomatedCheckBlockReason::class, RedirectPolicy::class]),

    expect('Heptapod\Enums')
        ->toBeEnums()
        ->ignoring('Heptapod\Enums\Concerns'),

    expect('Heptapod\Features')
        ->toBeClasses()
        ->ignoring('Heptapod\Features\Concerns'),

    expect('Heptapod\Features')
        ->toHaveMethod('resolve')
        ->ignoring('Heptapod\Features\Concerns'),

    expect('Heptapod\Exceptions')
        ->classes()
        ->toImplement('Throwable')
        ->ignoring('Heptapod\Exceptions\Handler'),

    // Addons catch typed transport failures without importing core-only Exceptions.
    expect('Heptapod')
        ->not->toImplement(Throwable::class)
        ->ignoring([...ArchDrift::ThrowablesOutsideExceptions, 'Heptapod\Exceptions', ExternalNetworkException::class, ExternalTimeoutException::class, FetchException::class, TargetResolutionException::class, StaleEvaluation::class, RunCancelled::class]),

    expect('Heptapod\Http\Middleware')
        ->classes()
        ->toHaveMethod('handle'),

    expect('Heptapod\Models')
        ->classes()
        ->toExtend(Model::class)
        ->ignoring('Heptapod\Models\Scopes'),

    expect('Heptapod\Models')
        ->classes()
        ->not->toHaveSuffix('Model'),

    expect('Heptapod')
        ->not->toExtend(Model::class)
        ->ignoring('Heptapod\Models'),

    expect('Heptapod\Http\Requests')
        ->classes()
        ->toHaveSuffix('Request'),

    expect('Heptapod\Http\Requests')
        ->classes()
        ->toExtend(FormRequest::class),

    expect('Heptapod\Http\Requests')
        ->toHaveMethod('rules'),

    expect('Heptapod')
        ->not->toExtend(FormRequest::class)
        ->ignoring('Heptapod\Http\Requests'),

    expect('Heptapod\Console\Commands')
        ->classes()
        ->toHaveSuffix('Command')
        ->ignoring(ArchDrift::CommandsWithoutSuffix),

    expect('Heptapod\Console\Commands')
        ->classes()
        ->toExtend(Command::class),

    expect('Heptapod\Console\Commands')
        ->classes()
        ->toHaveMethod('handle'),

    expect('Heptapod')
        ->not->toExtend(Command::class)
        ->ignoring('Heptapod\Console\Commands'),

    expect('Heptapod\Mail')
        ->classes()
        ->toExtend(Mailable::class)
        ->ignoring(ArchDrift::MailNonMailables),

    expect('Heptapod\Mail')
        ->classes()
        ->toImplement(ShouldQueue::class)
        ->ignoring(ArchDrift::MailNonMailables),

    expect('Heptapod')
        ->not->toExtend(Mailable::class)
        ->ignoring('Heptapod\Mail'),

    expect('Heptapod\Jobs')
        ->classes()
        ->toImplement(ShouldQueue::class),

    expect('Heptapod\Jobs')
        ->classes()
        ->toHaveMethod('handle'),

    expect('Heptapod\Listeners')
        ->toHaveMethod('handle'),

    expect('Heptapod\Notifications')
        ->classes()
        ->toExtend(Notification::class)
        ->ignoring(ArchDrift::NotificationsNonNotifications),

    expect('Heptapod')
        ->not->toExtend(Notification::class)
        // Notifications consumed by addon channel drivers must live in Contracts.
        ->ignoring(['Heptapod\Notifications', 'Heptapod\Contracts\Notifications']),

    expect('Heptapod\Providers')
        ->toHaveSuffix('ServiceProvider'),

    expect('Heptapod\Providers')
        ->classes()
        ->toExtend(ServiceProvider::class),

    expect('Heptapod\Providers')
        ->not->toBeUsed(),

    expect('Heptapod')
        ->not->toExtend(ServiceProvider::class)
        ->ignoring('Heptapod\Providers'),

    expect('Heptapod')
        ->not->toHaveSuffix('ServiceProvider')
        ->ignoring('Heptapod\Providers'),

    expect('Heptapod')
        ->not->toHaveSuffix('Controller')
        ->ignoring('Heptapod\Http\Controllers'),

    expect('Heptapod\Http\Controllers')
        ->classes()
        ->toHaveSuffix('Controller'),

    expect('Heptapod\Http')
        ->toOnlyBeUsedIn(['Heptapod\Http', 'Heptapod\Providers']),

    expect('Heptapod\Http\Controllers')
        ->not->toHavePublicMethodsBesides(ResourceControllerMethods)
        // Each drift class keeps its exact extra methods in ArchDrift and is checked by the rule below.
        ->ignoring(array_keys(ArchDrift::NonResourceControllerMethods)),

    expect([
        'dd',
        'ddd',
        'dump',
        'env',
        'exit',
        'ray',
    ])->not->toBeUsed(),

    expect('Heptapod\Policies')
        ->classes()
        ->toHaveSuffix('Policy'),

    expect('Heptapod\Attributes')
        ->classes()
        ->toImplement(ContextualAttribute::class)
        ->toHaveAttribute('Attribute')
        ->toHaveMethod('resolve'),
]);

arch()->preset()->heptapodLaravel();

foreach (ArchDrift::NonResourceControllerMethods as $controller => $extra) {
    arch('drift controller '.class_basename($controller).' adds only its listed non-resource methods')
        ->expect($controller)
        ->not->toHavePublicMethodsBesides([...ResourceControllerMethods, ...$extra]);
}
