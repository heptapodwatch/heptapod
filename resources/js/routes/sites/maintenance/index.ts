import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
export const end = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: end.url(args, options),
    method: 'post',
})

end.definition = {
    methods: ["post"],
    url: '/sites/{site}/maintenance/end',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
end.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return end.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
end.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: end.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
const endForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: end.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndSiteMaintenanceController::__invoke
* @see app/Http/Controllers/EndSiteMaintenanceController.php:14
* @route '/sites/{site}/maintenance/end'
*/
endForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: end.url(args, options),
    method: 'post',
})

end.form = endForm

const maintenance = {
    end: Object.assign(end, end),
}

export default maintenance