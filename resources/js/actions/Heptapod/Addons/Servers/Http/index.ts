import ServerReportController from './ServerReportController'
import ServersController from './ServersController'
import ServerSitesController from './ServerSitesController'

const Http = {
    ServerReportController: Object.assign(ServerReportController, ServerReportController),
    ServersController: Object.assign(ServersController, ServersController),
    ServerSitesController: Object.assign(ServerSitesController, ServerSitesController),
}

export default Http