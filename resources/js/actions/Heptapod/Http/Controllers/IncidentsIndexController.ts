import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
const IncidentsIndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentsIndexController.url(options),
    method: 'get',
})

IncidentsIndexController.definition = {
    methods: ["get","head"],
    url: '/incidents',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
IncidentsIndexController.url = (options?: RouteQueryOptions) => {
    return IncidentsIndexController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
IncidentsIndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
IncidentsIndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IncidentsIndexController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
const IncidentsIndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
IncidentsIndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
IncidentsIndexControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentsIndexController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IncidentsIndexController.form = IncidentsIndexControllerForm

export default IncidentsIndexController