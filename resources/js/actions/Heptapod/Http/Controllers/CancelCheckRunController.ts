import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
const CancelCheckRunController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: CancelCheckRunController.url(args, options),
    method: 'post',
})

CancelCheckRunController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
CancelCheckRunController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return CancelCheckRunController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
CancelCheckRunController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: CancelCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
const CancelCheckRunControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: CancelCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
CancelCheckRunControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: CancelCheckRunController.url(args, options),
    method: 'post',
})

CancelCheckRunController.form = CancelCheckRunControllerForm

export default CancelCheckRunController