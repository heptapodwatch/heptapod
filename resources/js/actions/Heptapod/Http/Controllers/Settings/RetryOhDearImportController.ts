import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
const RetryOhDearImportController = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RetryOhDearImportController.url(args, options),
    method: 'post',
})

RetryOhDearImportController.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/runs/{run}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
RetryOhDearImportController.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { run: args }
    }

    if (Array.isArray(args)) {
        args = {
            run: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        run: args.run,
    }

    return RetryOhDearImportController.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
RetryOhDearImportController.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RetryOhDearImportController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
const RetryOhDearImportControllerForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RetryOhDearImportController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
RetryOhDearImportControllerForm.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RetryOhDearImportController.url(args, options),
    method: 'post',
})

RetryOhDearImportController.form = RetryOhDearImportControllerForm

export default RetryOhDearImportController