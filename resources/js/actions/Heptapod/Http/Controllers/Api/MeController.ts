import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
const MeController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MeController.url(options),
    method: 'get',
})

MeController.definition = {
    methods: ["get","head"],
    url: '/api/me',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
MeController.url = (options?: RouteQueryOptions) => {
    return MeController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
MeController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MeController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
MeController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: MeController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
const MeControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MeController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
MeControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MeController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:17
* @route '/api/me'
*/
MeControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MeController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

MeController.form = MeControllerForm

export default MeController