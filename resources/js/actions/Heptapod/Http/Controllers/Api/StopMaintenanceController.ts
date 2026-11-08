import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
const StopMaintenanceController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StopMaintenanceController.url(args, options),
    method: 'post',
})

StopMaintenanceController.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/stop-maintenance',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
StopMaintenanceController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { monitor: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { monitor: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
    }

    return StopMaintenanceController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
StopMaintenanceController.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StopMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
const StopMaintenanceControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StopMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
StopMaintenanceControllerForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StopMaintenanceController.url(args, options),
    method: 'post',
})

StopMaintenanceController.form = StopMaintenanceControllerForm

export default StopMaintenanceController