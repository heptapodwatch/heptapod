import Api from './Api'
import InvitationController from './InvitationController'
import UpcomingController from './UpcomingController'
import ActivityController from './ActivityController'
import RunsController from './RunsController'
import SearchController from './SearchController'
import ClientsIndexController from './ClientsIndexController'
import ClientSettingsController from './ClientSettingsController'
import ClientDetailController from './ClientDetailController'
import ClientLogoController from './ClientLogoController'
import RestoreClientController from './RestoreClientController'
import ArchiveClientController from './ArchiveClientController'
import ClientMoveBlockersController from './ClientMoveBlockersController'
import ClientMessagesController from './ClientMessagesController'
import ClientMessageAttachmentController from './ClientMessageAttachmentController'
import ClientMessagePreviewController from './ClientMessagePreviewController'
import MaintenanceController from './MaintenanceController'
import MaintenanceSeriesController from './MaintenanceSeriesController'
import EndMaintenanceController from './EndMaintenanceController'
import IncidentsIndexController from './IncidentsIndexController'
import IncidentExportController from './IncidentExportController'
import IncidentAlertPreviewController from './IncidentAlertPreviewController'
import IncidentDetailController from './IncidentDetailController'
import IncidentActionsController from './IncidentActionsController'
import SitesIndexController from './SitesIndexController'
import AddSiteController from './AddSiteController'
import SiteProbeController from './SiteProbeController'
import SiteOverviewController from './SiteOverviewController'
import EndSiteMaintenanceController from './EndSiteMaintenanceController'
import SiteIconController from './SiteIconController'
import RefreshSiteIconController from './RefreshSiteIconController'
import SiteSettingsController from './SiteSettingsController'
import SiteMonitoringController from './SiteMonitoringController'
import CheckTabController from './CheckTabController'
import FindingsExportController from './FindingsExportController'
import UpdateCheckSettingsController from './UpdateCheckSettingsController'
import EnableSiteCheckController from './EnableSiteCheckController'
import HealthSettingsProbeController from './HealthSettingsProbeController'
import RequestCheckRunController from './RequestCheckRunController'
import CheckRunAttemptController from './CheckRunAttemptController'
import AcceptDnsSnapshotController from './AcceptDnsSnapshotController'
import RotateCronPingUrlController from './RotateCronPingUrlController'
import CancelCheckRunController from './CancelCheckRunController'
import RequestSiteChecksController from './RequestSiteChecksController'
import Notifications from './Notifications'
import Settings from './Settings'
import CronPingController from './CronPingController'

const Controllers = {
    Api: Object.assign(Api, Api),
    InvitationController: Object.assign(InvitationController, InvitationController),
    UpcomingController: Object.assign(UpcomingController, UpcomingController),
    ActivityController: Object.assign(ActivityController, ActivityController),
    RunsController: Object.assign(RunsController, RunsController),
    SearchController: Object.assign(SearchController, SearchController),
    ClientsIndexController: Object.assign(ClientsIndexController, ClientsIndexController),
    ClientSettingsController: Object.assign(ClientSettingsController, ClientSettingsController),
    ClientDetailController: Object.assign(ClientDetailController, ClientDetailController),
    ClientLogoController: Object.assign(ClientLogoController, ClientLogoController),
    RestoreClientController: Object.assign(RestoreClientController, RestoreClientController),
    ArchiveClientController: Object.assign(ArchiveClientController, ArchiveClientController),
    ClientMoveBlockersController: Object.assign(ClientMoveBlockersController, ClientMoveBlockersController),
    ClientMessagesController: Object.assign(ClientMessagesController, ClientMessagesController),
    ClientMessageAttachmentController: Object.assign(ClientMessageAttachmentController, ClientMessageAttachmentController),
    ClientMessagePreviewController: Object.assign(ClientMessagePreviewController, ClientMessagePreviewController),
    MaintenanceController: Object.assign(MaintenanceController, MaintenanceController),
    MaintenanceSeriesController: Object.assign(MaintenanceSeriesController, MaintenanceSeriesController),
    EndMaintenanceController: Object.assign(EndMaintenanceController, EndMaintenanceController),
    IncidentsIndexController: Object.assign(IncidentsIndexController, IncidentsIndexController),
    IncidentExportController: Object.assign(IncidentExportController, IncidentExportController),
    IncidentAlertPreviewController: Object.assign(IncidentAlertPreviewController, IncidentAlertPreviewController),
    IncidentDetailController: Object.assign(IncidentDetailController, IncidentDetailController),
    IncidentActionsController: Object.assign(IncidentActionsController, IncidentActionsController),
    SitesIndexController: Object.assign(SitesIndexController, SitesIndexController),
    AddSiteController: Object.assign(AddSiteController, AddSiteController),
    SiteProbeController: Object.assign(SiteProbeController, SiteProbeController),
    SiteOverviewController: Object.assign(SiteOverviewController, SiteOverviewController),
    EndSiteMaintenanceController: Object.assign(EndSiteMaintenanceController, EndSiteMaintenanceController),
    SiteIconController: Object.assign(SiteIconController, SiteIconController),
    RefreshSiteIconController: Object.assign(RefreshSiteIconController, RefreshSiteIconController),
    SiteSettingsController: Object.assign(SiteSettingsController, SiteSettingsController),
    SiteMonitoringController: Object.assign(SiteMonitoringController, SiteMonitoringController),
    CheckTabController: Object.assign(CheckTabController, CheckTabController),
    FindingsExportController: Object.assign(FindingsExportController, FindingsExportController),
    UpdateCheckSettingsController: Object.assign(UpdateCheckSettingsController, UpdateCheckSettingsController),
    EnableSiteCheckController: Object.assign(EnableSiteCheckController, EnableSiteCheckController),
    HealthSettingsProbeController: Object.assign(HealthSettingsProbeController, HealthSettingsProbeController),
    RequestCheckRunController: Object.assign(RequestCheckRunController, RequestCheckRunController),
    CheckRunAttemptController: Object.assign(CheckRunAttemptController, CheckRunAttemptController),
    AcceptDnsSnapshotController: Object.assign(AcceptDnsSnapshotController, AcceptDnsSnapshotController),
    RotateCronPingUrlController: Object.assign(RotateCronPingUrlController, RotateCronPingUrlController),
    CancelCheckRunController: Object.assign(CancelCheckRunController, CancelCheckRunController),
    RequestSiteChecksController: Object.assign(RequestSiteChecksController, RequestSiteChecksController),
    Notifications: Object.assign(Notifications, Notifications),
    Settings: Object.assign(Settings, Settings),
    CronPingController: Object.assign(CronPingController, CronPingController),
}

export default Controllers