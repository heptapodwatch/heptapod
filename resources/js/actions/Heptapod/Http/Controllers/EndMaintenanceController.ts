import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
const EndMaintenanceController = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EndMaintenanceController.url(args, options),
    method: 'post',
})

EndMaintenanceController.definition = {
    methods: ["post"],
    url: '/maintenance/{window}/end',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
EndMaintenanceController.url = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { window: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { window: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            window: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        window: typeof args.window === 'object'
        ? args.window.id
        : args.window,
    }

    return EndMaintenanceController.definition.url
            .replace('{window}', parsedArgs.window.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
EndMaintenanceController.post = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EndMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
const EndMaintenanceControllerForm = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EndMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
EndMaintenanceControllerForm.post = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EndMaintenanceController.url(args, options),
    method: 'post',
})

EndMaintenanceController.form = EndMaintenanceControllerForm

export default EndMaintenanceController