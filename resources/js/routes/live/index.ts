import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
export const changes = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: changes.url(options),
    method: 'get',
})

changes.definition = {
    methods: ["get","head"],
    url: '/live/changes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
changes.url = (options?: RouteQueryOptions) => {
    return changes.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
changes.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: changes.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
changes.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: changes.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
const changesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: changes.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
changesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: changes.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\LiveChangesController::__invoke
* @see app/Http/Controllers/LiveChangesController.php:20
* @route '/live/changes'
*/
changesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: changes.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

changes.form = changesForm

const live = {
    changes: Object.assign(changes, changes),
}

export default live