import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
const NotificationExplanationController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NotificationExplanationController.url(options),
    method: 'get',
})

NotificationExplanationController.definition = {
    methods: ["get","head"],
    url: '/notifications/explain',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
NotificationExplanationController.url = (options?: RouteQueryOptions) => {
    return NotificationExplanationController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
NotificationExplanationController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NotificationExplanationController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
NotificationExplanationController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: NotificationExplanationController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
const NotificationExplanationControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: NotificationExplanationController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
NotificationExplanationControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: NotificationExplanationController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
NotificationExplanationControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: NotificationExplanationController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

NotificationExplanationController.form = NotificationExplanationControllerForm

export default NotificationExplanationController