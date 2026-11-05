import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:19
* @route '/api/monitors/{monitor}/start-maintenance'
*/
const StartMaintenanceController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StartMaintenanceController.url(args, options),
    method: 'post',
})

StartMaintenanceController.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/start-maintenance',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:19
* @route '/api/monitors/{monitor}/start-maintenance'
*/
StartMaintenanceController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return StartMaintenanceController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:19
* @route '/api/monitors/{monitor}/start-maintenance'
*/
StartMaintenanceController.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StartMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:19
* @route '/api/monitors/{monitor}/start-maintenance'
*/
const StartMaintenanceControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StartMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:19
* @route '/api/monitors/{monitor}/start-maintenance'
*/
StartMaintenanceControllerForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StartMaintenanceController.url(args, options),
    method: 'post',
})

StartMaintenanceController.form = StartMaintenanceControllerForm

export default StartMaintenanceController