import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
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

const runs = {
    index: Object.assign(index, index),
}

export default runs