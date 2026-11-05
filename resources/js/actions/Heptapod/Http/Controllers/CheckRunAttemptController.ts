import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
const CheckRunAttemptController = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckRunAttemptController.url(args, options),
    method: 'get',
})

CheckRunAttemptController.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}/attempts/{attempt}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
CheckRunAttemptController.url = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions) => {
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

    return CheckRunAttemptController.definition.url
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
CheckRunAttemptController.get = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckRunAttemptController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
CheckRunAttemptController.head = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CheckRunAttemptController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
const CheckRunAttemptControllerForm = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunAttemptController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
CheckRunAttemptControllerForm.get = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunAttemptController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckRunAttemptController::__invoke
* @see app/Http/Controllers/CheckRunAttemptController.php:24
* @route '/sites/{site}/checks/{check}/attempts/{attempt}'
*/
CheckRunAttemptControllerForm.head = (args: { site: string | number, check: string | number, attempt: string | number } | [site: string | number, check: string | number, attempt: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunAttemptController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

CheckRunAttemptController.form = CheckRunAttemptControllerForm

export default CheckRunAttemptController