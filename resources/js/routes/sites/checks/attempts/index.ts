import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
export const show = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}/attempts/{attempt}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
show.url = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
            attempt: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
        attempt: args.attempt,
    }

    return show.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace('{attempt}', parsedArgs.attempt.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
show.get = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
show.head = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
const showForm = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
showForm.get = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
showForm.head = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const attempts = {
    show: Object.assign(show, show),
}

export default attempts