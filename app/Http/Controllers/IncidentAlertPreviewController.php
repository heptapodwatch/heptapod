<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentAlertPreviewController extends Controller
{
    public function __invoke(Request $request, int $incident, int $event, IncidentsReader $reader, NotificationMessage $messages): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);
        $reader->scoped($user)->whereKey($incident)->firstOrFail();
        $events = Event::incidentAlertPreviewTypes()->with(['site.client', 'check', 'incident'])->where('incident_id', $incident)->whereKey($event);
        $row = $events->firstOrFail();
        $deliveries = Delivery::query()->with('destination')->where('event_id', $row->id)
            ->where('state', DeliveryState::Sent->value)->where('suppressed', false)->where('digest', false)
            ->whereHas('destination', static fn (Builder $query): Builder => $query->where('channel', 'mail'));
        $deliveries->getQuery()->orderBy('id');
        $delivery = $deliveries->firstOrFail();
        $destination = $delivery->destination ?? throw new \LogicException('Accepted mail delivery has no destination.');
        abort_unless(app()->bound(AlertPreviewRenderer::class), 404);
        $config = $destination->destinationConfig();
        $preview = app(AlertPreviewRenderer::class)->render($messages->build($row, $destination, $delivery->category), $config->locale, $config->timezone, $config->instanceName, $config->instanceUrl);

        return response()->json([
            'subject' => $preview['subject'], 'html' => $preview['html'],
            'destination' => ['id' => $destination->id, 'label' => $destination->label, 'address' => $config->string('email'),
                'locale' => $config->locale, 'timezone' => $config->timezone],
        ])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
