import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
const StartOhDearImportController = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StartOhDearImportController.url(args, options),
    method: 'post',
})

StartOhDearImportController.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/runs/{run}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
StartOhDearImportController.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return StartOhDearImportController.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
StartOhDearImportController.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StartOhDearImportController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
const StartOhDearImportControllerForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StartOhDearImportController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
StartOhDearImportControllerForm.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: StartOhDearImportController.url(args, options),
    method: 'post',
})

StartOhDearImportController.form = StartOhDearImportControllerForm

export default StartOhDearImportController