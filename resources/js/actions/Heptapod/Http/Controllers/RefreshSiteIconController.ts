import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
const RefreshSiteIconController = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RefreshSiteIconController.url(args, options),
    method: 'post',
})

RefreshSiteIconController.definition = {
    methods: ["post"],
    url: '/sites/{site}/icon',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
RefreshSiteIconController.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return RefreshSiteIconController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
RefreshSiteIconController.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RefreshSiteIconController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
const RefreshSiteIconControllerForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RefreshSiteIconController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RefreshSiteIconController::__invoke
* @see app/Http/Controllers/RefreshSiteIconController.php:14
* @route '/sites/{site}/icon'
*/
RefreshSiteIconControllerForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RefreshSiteIconController.url(args, options),
    method: 'post',
})

RefreshSiteIconController.form = RefreshSiteIconControllerForm

export default RefreshSiteIconController