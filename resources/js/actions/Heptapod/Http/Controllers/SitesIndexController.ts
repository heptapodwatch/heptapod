import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
const SitesIndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SitesIndexController.url(options),
    method: 'get',
})

SitesIndexController.definition = {
    methods: ["get","head"],
    url: '/sites',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
SitesIndexController.url = (options?: RouteQueryOptions) => {
    return SitesIndexController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
SitesIndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SitesIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
SitesIndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SitesIndexController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
const SitesIndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SitesIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
SitesIndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SitesIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
SitesIndexControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SitesIndexController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

SitesIndexController.form = SitesIndexControllerForm

export default SitesIndexController