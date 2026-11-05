import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
const RequestSiteChecksController = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestSiteChecksController.url(args, options),
    method: 'post',
})

RequestSiteChecksController.definition = {
    methods: ["post"],
    url: '/sites/{site}/run-all',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
RequestSiteChecksController.url = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return RequestSiteChecksController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
RequestSiteChecksController.post = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestSiteChecksController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
const RequestSiteChecksControllerForm = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestSiteChecksController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
RequestSiteChecksControllerForm.post = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestSiteChecksController.url(args, options),
    method: 'post',
})

RequestSiteChecksController.form = RequestSiteChecksControllerForm

export default RequestSiteChecksController