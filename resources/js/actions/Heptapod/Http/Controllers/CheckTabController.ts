import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
const CheckTabController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckTabController.url(args, options),
    method: 'get',
})

CheckTabController.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
CheckTabController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return CheckTabController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
CheckTabController.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckTabController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
CheckTabController.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CheckTabController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
const CheckTabControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckTabController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
CheckTabControllerForm.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckTabController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
CheckTabControllerForm.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckTabController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

CheckTabController.form = CheckTabControllerForm

export default CheckTabController