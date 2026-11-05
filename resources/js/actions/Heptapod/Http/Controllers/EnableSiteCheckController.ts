import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
const EnableSiteCheckController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EnableSiteCheckController.url(args, options),
    method: 'post',
})

EnableSiteCheckController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
EnableSiteCheckController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return EnableSiteCheckController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
EnableSiteCheckController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: EnableSiteCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
const EnableSiteCheckControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EnableSiteCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
EnableSiteCheckControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: EnableSiteCheckController.url(args, options),
    method: 'post',
})

EnableSiteCheckController.form = EnableSiteCheckControllerForm

export default EnableSiteCheckController