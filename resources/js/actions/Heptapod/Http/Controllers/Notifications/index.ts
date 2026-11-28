import NotificationChannelsController from './NotificationChannelsController'
import NotificationChannelEnabledController from './NotificationChannelEnabledController'
import NotificationChannelTestController from './NotificationChannelTestController'
import NotificationSiteMutesController from './NotificationSiteMutesController'
import NotificationScheduleController from './NotificationScheduleController'
import NotificationExplanationController from './NotificationExplanationController'
import ClientNotificationSettingsController from './ClientNotificationSettingsController'
import OwnNotificationSettingsController from './OwnNotificationSettingsController'
import TeamNotificationSettingsController from './TeamNotificationSettingsController'
import DismissNotificationMigrationReportController from './DismissNotificationMigrationReportController'

const Notifications = {
    NotificationChannelsController: Object.assign(NotificationChannelsController, NotificationChannelsController),
    NotificationChannelEnabledController: Object.assign(NotificationChannelEnabledController, NotificationChannelEnabledController),
    NotificationChannelTestController: Object.assign(NotificationChannelTestController, NotificationChannelTestController),
    NotificationSiteMutesController: Object.assign(NotificationSiteMutesController, NotificationSiteMutesController),
    NotificationScheduleController: Object.assign(NotificationScheduleController, NotificationScheduleController),
    NotificationExplanationController: Object.assign(NotificationExplanationController, NotificationExplanationController),
    ClientNotificationSettingsController: Object.assign(ClientNotificationSettingsController, ClientNotificationSettingsController),
    OwnNotificationSettingsController: Object.assign(OwnNotificationSettingsController, OwnNotificationSettingsController),
    TeamNotificationSettingsController: Object.assign(TeamNotificationSettingsController, TeamNotificationSettingsController),
    DismissNotificationMigrationReportController: Object.assign(DismissNotificationMigrationReportController, DismissNotificationMigrationReportController),
}

export default Notifications