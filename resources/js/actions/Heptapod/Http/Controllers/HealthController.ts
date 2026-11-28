import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
const HealthController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HealthController.url(options),
    method: 'get',
})

HealthController.definition = {
    methods: ["get","head"],
    url: '/up',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
HealthController.url = (options?: RouteQueryOptions) => {
    return HealthController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
HealthController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HealthController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
HealthController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: HealthController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
const HealthControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: HealthController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
HealthControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: HealthController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\HealthController::__invoke
* @see app/Http/Controllers/HealthController.php:17
* @route '/up'
*/
HealthControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: HealthController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

HealthController.form = HealthControllerForm

export default HealthController