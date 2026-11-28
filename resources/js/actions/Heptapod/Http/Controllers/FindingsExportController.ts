import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
const FindingsExportController = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FindingsExportController.url(args, options),
    method: 'get',
})

FindingsExportController.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}/findings.{format}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
FindingsExportController.url = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
            format: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
        format: args.format,
    }

    return FindingsExportController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace('{format}', parsedArgs.format.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
FindingsExportController.get = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FindingsExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
FindingsExportController.head = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: FindingsExportController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
const FindingsExportControllerForm = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
FindingsExportControllerForm.get = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:41
* @route '/sites/{site}/checks/{check}/findings.{format}'
*/
FindingsExportControllerForm.head = (args: { site: string | number, check: string | number, format: string | number } | [site: string | number, check: string | number, format: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: FindingsExportController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

FindingsExportController.form = FindingsExportControllerForm

export default FindingsExportController