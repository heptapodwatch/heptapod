import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
const SiteOverviewController = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SiteOverviewController.url(args, options),
    method: 'get',
})

SiteOverviewController.definition = {
    methods: ["get","head"],
    url: '/sites/{site}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
SiteOverviewController.url = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
    }

    return SiteOverviewController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
SiteOverviewController.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SiteOverviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
SiteOverviewController.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SiteOverviewController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
const SiteOverviewControllerForm = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SiteOverviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
SiteOverviewControllerForm.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SiteOverviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
SiteOverviewControllerForm.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SiteOverviewController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

SiteOverviewController.form = SiteOverviewControllerForm

export default SiteOverviewController