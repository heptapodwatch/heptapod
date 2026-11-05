import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
const EndSiteMaintenanceController = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EndSiteMaintenanceController.url(args, options),
    method: 'post',
})

EndSiteMaintenanceController.definition = {
    methods: ["post"],
    url: '/sites/{site}/maintenance/end',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
EndSiteMaintenanceController.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return EndSiteMaintenanceController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
EndSiteMaintenanceController.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EndSiteMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
const EndSiteMaintenanceControllerForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EndSiteMaintenanceController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
EndSiteMaintenanceControllerForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EndSiteMaintenanceController.url(args, options),
    method: 'post',
})

EndSiteMaintenanceController.form = EndSiteMaintenanceControllerForm

export default EndSiteMaintenanceController