import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import InstanceController from './InstanceController'
import SystemHealthController from './SystemHealthController'
import BackupController from './BackupController'
import UpdateStatusController from './UpdateStatusController'
import MembersController from './MembersController'
import InvitationResendController from './InvitationResendController'
import InvitationRevokeController from './InvitationRevokeController'
import ApiTokensController from './ApiTokensController'
import ImportOhDearController from './ImportOhDearController'
import PlanOhDearImportController from './PlanOhDearImportController'
import StartOhDearImportController from './StartOhDearImportController'
import RetryOhDearImportController from './RetryOhDearImportController'
import AlertSensitivityController from './AlertSensitivityController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    InstanceController: Object.assign(InstanceController, InstanceController),
    SystemHealthController: Object.assign(SystemHealthController, SystemHealthController),
    BackupController: Object.assign(BackupController, BackupController),
    UpdateStatusController: Object.assign(UpdateStatusController, UpdateStatusController),
    MembersController: Object.assign(MembersController, MembersController),
    InvitationResendController: Object.assign(InvitationResendController, InvitationResendController),
    InvitationRevokeController: Object.assign(InvitationRevokeController, InvitationRevokeController),
    ApiTokensController: Object.assign(ApiTokensController, ApiTokensController),
    ImportOhDearController: Object.assign(ImportOhDearController, ImportOhDearController),
    PlanOhDearImportController: Object.assign(PlanOhDearImportController, PlanOhDearImportController),
    StartOhDearImportController: Object.assign(StartOhDearImportController, StartOhDearImportController),
    RetryOhDearImportController: Object.assign(RetryOhDearImportController, RetryOhDearImportController),
    AlertSensitivityController: Object.assign(AlertSensitivityController, AlertSensitivityController),
}

export default Settings