import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\DisableSiteCheckController::__invoke
* @see app/Http/Controllers/DisableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/disable'
*/
const DisableSiteCheckController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DisableSiteCheckController.url(args, options),
    method: 'post',
})

DisableSiteCheckController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/disable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\DisableSiteCheckController::__invoke
* @see app/Http/Controllers/DisableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/disable'
*/
DisableSiteCheckController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return DisableSiteCheckController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\DisableSiteCheckController::__invoke
* @see app/Http/Controllers/DisableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/disable'
*/
DisableSiteCheckController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DisableSiteCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\DisableSiteCheckController::__invoke
* @see app/Http/Controllers/DisableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/disable'
*/
const DisableSiteCheckControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DisableSiteCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\DisableSiteCheckController::__invoke
* @see app/Http/Controllers/DisableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/disable'
*/
DisableSiteCheckControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DisableSiteCheckController.url(args, options),
    method: 'post',
})

DisableSiteCheckController.form = DisableSiteCheckControllerForm

export default DisableSiteCheckController