import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
const ActivityController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ActivityController.url(options),
    method: 'get',
})

ActivityController.definition = {
    methods: ["get","head"],
    url: '/api/activity',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
ActivityController.url = (options?: RouteQueryOptions) => {
    return ActivityController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
ActivityController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
ActivityController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ActivityController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
const ActivityControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
ActivityControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ActivityController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
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