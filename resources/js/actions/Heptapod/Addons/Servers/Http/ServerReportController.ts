import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
const ServerReportController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ServerReportController.url(options),
    method: 'post',
})

ServerReportController.definition = {
    methods: ["post"],
    url: '/agent/v1/report',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
ServerReportController.url = (options?: RouteQueryOptions) => {
    return ServerReportController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
ServerReportController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ServerReportController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
const ServerReportControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ServerReportController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
ServerReportControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ServerReportController.url(options),
    method: 'post',
})

ServerReportController.form = ServerReportControllerForm

export default ServerReportController