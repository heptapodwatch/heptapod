import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
const ActivityController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ActivityController.url(options),
    method: 'get',
})

ActivityController.definition = {
    methods: ["get","head"],
    url: '/activity',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
ActivityController.url = (options?: RouteQueryOptions) => {
    return ActivityController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
ActivityController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
ActivityController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ActivityController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
const ActivityControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
ActivityControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ActivityController::__invoke
* @see app/Http/Controllers/ActivityController.php:22
* @route '/activity'
*/
ActivityControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ActivityController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ActivityController.form = ActivityControllerForm

export default ActivityController