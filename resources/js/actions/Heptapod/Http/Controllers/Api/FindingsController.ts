import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
const FindingsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FindingsController.url(options),
    method: 'get',
})

FindingsController.definition = {
    methods: ["get","head"],
    url: '/api/findings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
FindingsController.url = (options?: RouteQueryOptions) => {
    return FindingsController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
FindingsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FindingsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
FindingsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: FindingsController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
const FindingsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
FindingsControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:39
* @route '/api/findings'
*/
FindingsControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

FindingsController.form = FindingsControllerForm

export default FindingsController