import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
export const refresh = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/sites/{site}/icon',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
refresh.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return refresh.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
refresh.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
const refreshForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: refresh.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
refreshForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: refresh.url(args, options),
    method: 'post',
})

refresh.form = refreshForm

const icon = {
    refresh: Object.assign(refresh, refresh),
}

export default icon