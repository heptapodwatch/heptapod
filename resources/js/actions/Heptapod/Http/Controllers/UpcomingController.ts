import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
const UpcomingController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UpcomingController.url(options),
    method: 'get',
})

UpcomingController.definition = {
    methods: ["get","head"],
    url: '/upcoming',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
UpcomingController.url = (options?: RouteQueryOptions) => {
    return UpcomingController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
UpcomingController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
UpcomingController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: UpcomingController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
const UpcomingControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
UpcomingControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UpcomingController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
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