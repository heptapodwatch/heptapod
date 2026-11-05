import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:20
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
const SyncCronTasksController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SyncCronTasksController.url(args, options),
    method: 'post',
})

SyncCronTasksController.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/cron-checks/sync',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:20
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
SyncCronTasksController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return SyncCronTasksController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:20
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
SyncCronTasksController.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SyncCronTasksController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:20
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
const SyncCronTasksControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SyncCronTasksController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:20
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
SyncCronTasksControllerForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SyncCronTasksController.url(args, options),
    method: 'post',
})

SyncCronTasksController.form = SyncCronTasksControllerForm

export default SyncCronTasksController