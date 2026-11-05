import OpenApiDocumentController from './OpenApiDocumentController'
import MaintenancePeriodController from './MaintenancePeriodController'
import StartMaintenanceController from './StartMaintenanceController'
import StopMaintenanceController from './StopMaintenanceController'
import CronTaskController from './CronTaskController'
import SyncCronTasksController from './SyncCronTasksController'
import SnoozeCronTaskController from './SnoozeCronTaskController'
import UnsnoozeCronTaskController from './UnsnoozeCronTaskController'
import MeController from './MeController'
import IncidentNoteController from './IncidentNoteController'
import IncidentRemindersController from './IncidentRemindersController'
import MonitorController from './MonitorController'
import MonitorUrlController from './MonitorUrlController'
import IncidentController from './IncidentController'
import ActivityController from './ActivityController'
import UpcomingController from './UpcomingController'
import FindingsController from './FindingsController'
import CheckSummaryController from './CheckSummaryController'
import CheckRunController from './CheckRunController'
import EnableCheckController from './EnableCheckController'
import DisableCheckController from './DisableCheckController'
import RunController from './RunController'
import RequestCheckRunController from './RequestCheckRunController'
import SnoozeCheckController from './SnoozeCheckController'
import UnsnoozeCheckController from './UnsnoozeCheckController'
import UptimeController from './UptimeController'
import UptimeMetricsController from './UptimeMetricsController'
import DowntimeController from './DowntimeController'
import TagController from './TagController'

const Api = {
    OpenApiDocumentController: Object.assign(OpenApiDocumentController, OpenApiDocumentController),
    MaintenancePeriodController: Object.assign(MaintenancePeriodController, MaintenancePeriodController),
    StartMaintenanceController: Object.assign(StartMaintenanceController, StartMaintenanceController),
    StopMaintenanceController: Object.assign(StopMaintenanceController, StopMaintenanceController),
    CronTaskController: Object.assign(CronTaskController, CronTaskController),
    SyncCronTasksController: Object.assign(SyncCronTasksController, SyncCronTasksController),
    SnoozeCronTaskController: Object.assign(SnoozeCronTaskController, SnoozeCronTaskController),
    UnsnoozeCronTaskController: Object.assign(UnsnoozeCronTaskController, UnsnoozeCronTaskController),
    MeController: Object.assign(MeController, MeController),
    IncidentNoteController: Object.assign(IncidentNoteController, IncidentNoteController),
    IncidentRemindersController: Object.assign(IncidentRemindersController, IncidentRemindersController),
    MonitorController: Object.assign(MonitorController, MonitorController),
    MonitorUrlController: Object.assign(MonitorUrlController, MonitorUrlController),
    IncidentController: Object.assign(IncidentController, IncidentController),
    ActivityController: Object.assign(ActivityController, ActivityController),
    UpcomingController: Object.assign(UpcomingController, UpcomingController),
    FindingsController: Object.assign(FindingsController, FindingsController),
    CheckSummaryController: Object.assign(CheckSummaryController, CheckSummaryController),
    CheckRunController: Object.assign(CheckRunController, CheckRunController),
    EnableCheckController: Object.assign(EnableCheckController, EnableCheckController),
    DisableCheckController: Object.assign(DisableCheckController, DisableCheckController),
    RunController: Object.assign(RunController, RunController),
    RequestCheckRunController: Object.assign(RequestCheckRunController, RequestCheckRunController),
    SnoozeCheckController: Object.assign(SnoozeCheckController, SnoozeCheckController),
    UnsnoozeCheckController: Object.assign(UnsnoozeCheckController, UnsnoozeCheckController),
    UptimeController: Object.assign(UptimeController, UptimeController),
    UptimeMetricsController: Object.assign(UptimeMetricsController, UptimeMetricsController),
    DowntimeController: Object.assign(DowntimeController, DowntimeController),
    TagController: Object.assign(TagController, TagController),
}

export default Api