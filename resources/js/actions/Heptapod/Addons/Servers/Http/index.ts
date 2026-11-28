import ServerReportController from './ServerReportController'
import ServersController from './ServersController'
import ServerSitesController from './ServerSitesController'
import Api from './Api'

const Http = {
    ServerReportController: Object.assign(ServerReportController, ServerReportController),
    ServersController: Object.assign(ServersController, ServersController),
    ServerSitesController: Object.assign(ServerSitesController, ServerSitesController),
    Api: Object.assign(Api, Api),
}

export default Http