import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
const RequestCheckRunController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestCheckRunController.url(args, options),
    method: 'post',
})

RequestCheckRunController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/run',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
RequestCheckRunController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return RequestCheckRunController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
RequestCheckRunController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
const RequestCheckRunControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
RequestCheckRunControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestCheckRunController.url(args, options),
    method: 'post',
})

RequestCheckRunController.form = RequestCheckRunControllerForm

export default RequestCheckRunController