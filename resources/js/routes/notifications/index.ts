import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
export const explain = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: explain.url(options),
    method: 'get',
})

explain.definition = {
    methods: ["get","head"],
    url: '/notifications/explain',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
explain.url = (options?: RouteQueryOptions) => {
    return explain.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
explain.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: explain.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
explain.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: explain.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
const explainForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: explain.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
explainForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: explain.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationExplanationController::__invoke
* @see app/Http/Controllers/Notifications/NotificationExplanationController.php:24
* @route '/notifications/explain'
*/
explainForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: explain.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

explain.form = explainForm

const notifications = {
    explain: Object.assign(explain, explain),
}

export default notifications