import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
const UpcomingController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UpcomingController.url(options),
    method: 'get',
})

UpcomingController.definition = {
    methods: ["get","head"],
    url: '/api/upcoming',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
UpcomingController.url = (options?: RouteQueryOptions) => {
    return UpcomingController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
UpcomingController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
UpcomingController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: UpcomingController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
const UpcomingControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
UpcomingControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
UpcomingControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UpcomingController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

UpcomingController.form = UpcomingControllerForm

export default UpcomingController