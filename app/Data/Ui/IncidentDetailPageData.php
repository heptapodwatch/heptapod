<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\Mail\MailDesign;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /incidents/{id}: one episode page for open and resolved incidents. Closures keep partial reloads lazy. */
#[TypeScript]
final class IncidentDetailPageData extends Data
{
    /**
     * @param  ClosureLazy|list<CheckRunData>  $runs
     * @param  ClosureLazy|list<IncidentDeliveryData>  $other_deliveries  Mail of incident events outside the timeline, e.g. missed scheduled tasks.
     * @param  ClosureLazy|list<IncidentTimelineEntryData>  $timeline
     * @param  ClosureLazy|list<IncidentData>  $related_incidents
     */
    public function __construct(
        public readonly ClosureLazy|SiteRowData $site,
        public readonly ClosureLazy|SiteIdentityData $header,
        public readonly ClosureLazy|ClientData $client,
        public readonly ClosureLazy|IncidentData $incident,
        public readonly ClosureLazy|array $runs,
        public readonly ClosureLazy|string|null $internal_note,
        public readonly ClosureLazy|string|null $note_author,
        public readonly ClosureLazy|string|null $note_updated_at,
        public readonly ClosureLazy|array $other_deliveries,
        public readonly ClosureLazy|IncidentEvidenceData|null $evidence,
        public readonly ClosureLazy|bool $alert_preview_available,
        /** The site's current or next maintenance window. */
        public readonly ClosureLazy|MaintenanceSiteHeaderData|null $maintenance,
        public readonly ClosureLazy|bool $opened_during_maintenance,
        public readonly ClosureLazy|CheckRunPresentationData|null $opening_presentation,
        /** Current state: null for a resolved incident. */
        public readonly ClosureLazy|CheckRunPresentationData|null $current_presentation,
        public readonly ClosureLazy|CheckDetailData|null $current_evidence,
        public readonly ClosureLazy|CheckHistoryData|null $current_findings,
        public readonly ClosureLazy|array $timeline,
        public readonly ClosureLazy|array $related_incidents,
        public readonly ClosureLazy|IncidentNotificationPageData|null $expanded_notifications,
        public readonly ClosureLazy|bool $can_run,
        public readonly ClosureLazy|bool $can_snooze,
        public readonly ClosureLazy|bool $can_unsnooze,
        public readonly ClosureLazy|string|null $opening_delivery_explanation,
        /** The instance mail design: the preset of a new message in Send to client. */
        public readonly ClosureLazy|MailDesign $mail_design,
    ) {}
}
