import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
const RunsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RunsController.url(options),
    method: 'get',
})

RunsController.definition = {
    methods: ["get","head"],
    url: '/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
RunsController.url = (options?: RouteQueryOptions) => {
    return RunsController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
RunsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RunsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
RunsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RunsController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
const RunsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: RunsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
RunsControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: RunsController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\RunsController::__invoke
* @see app/Http/Controllers/RunsController.php:26
* @route '/runs'
*/
RunsControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: RunsController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

RunsController.form = RunsControllerForm

export default RunsController