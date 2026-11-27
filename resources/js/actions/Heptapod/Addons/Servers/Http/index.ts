import ServerReportController from './ServerReportController'
import ServersController from './ServersController'

const Http = {
    ServerReportController: Object.assign(ServerReportController, ServerReportController),
    ServersController: Object.assign(ServersController, ServersController),
}

export default Http