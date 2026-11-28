import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import ApiTokensController from './ApiTokensController'
import SystemHealthController from './SystemHealthController'
import UpdateStatusController from './UpdateStatusController'
import MembersController from './MembersController'
import InvitationResendController from './InvitationResendController'
import InvitationRevokeController from './InvitationRevokeController'
import ImportOhDearController from './ImportOhDearController'
import PlanOhDearImportController from './PlanOhDearImportController'
import StartOhDearImportController from './StartOhDearImportController'
import RetryOhDearImportController from './RetryOhDearImportController'
import AlertSensitivityController from './AlertSensitivityController'
import InstanceController from './InstanceController'
import EmailController from './EmailController'
import EmailPreviewController from './EmailPreviewController'
import EmailLogoController from './EmailLogoController'
import BackupController from './BackupController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    ApiTokensController: Object.assign(ApiTokensController, ApiTokensController),
    SystemHealthController: Object.assign(SystemHealthController, SystemHealthController),
    UpdateStatusController: Object.assign(UpdateStatusController, UpdateStatusController),
    MembersController: Object.assign(MembersController, MembersController),
    InvitationResendController: Object.assign(InvitationResendController, InvitationResendController),
    InvitationRevokeController: Object.assign(InvitationRevokeController, InvitationRevokeController),
    ImportOhDearController: Object.assign(ImportOhDearController, ImportOhDearController),
    PlanOhDearImportController: Object.assign(PlanOhDearImportController, PlanOhDearImportController),
    StartOhDearImportController: Object.assign(StartOhDearImportController, StartOhDearImportController),
    RetryOhDearImportController: Object.assign(RetryOhDearImportController, RetryOhDearImportController),
    AlertSensitivityController: Object.assign(AlertSensitivityController, AlertSensitivityController),
    InstanceController: Object.assign(InstanceController, InstanceController),
    EmailController: Object.assign(EmailController, EmailController),
    EmailPreviewController: Object.assign(EmailPreviewController, EmailPreviewController),
    EmailLogoController: Object.assign(EmailLogoController, EmailLogoController),
    BackupController: Object.assign(BackupController, BackupController),
}

export default Settings