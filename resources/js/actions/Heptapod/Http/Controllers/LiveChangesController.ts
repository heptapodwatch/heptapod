import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
const LiveChangesController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LiveChangesController.url(options),
    method: 'get',
})

LiveChangesController.definition = {
    methods: ["get","head"],
    url: '/live/changes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
LiveChangesController.url = (options?: RouteQueryOptions) => {
    return LiveChangesController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
LiveChangesController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LiveChangesController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
LiveChangesController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LiveChangesController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
const LiveChangesControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: LiveChangesController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
LiveChangesControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: LiveChangesController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
LiveChangesControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: LiveChangesController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

LiveChangesController.form = LiveChangesControllerForm

export default LiveChangesController