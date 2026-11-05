import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/upcoming',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\UpcomingController::__invoke
* @see app/Http/Controllers/UpcomingController.php:20
* @route '/upcoming'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

const upcoming = {
    index: Object.assign(index, index),
}

export default upcoming