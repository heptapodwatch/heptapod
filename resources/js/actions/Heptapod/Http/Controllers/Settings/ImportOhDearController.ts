import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/import/ohdear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
export const show = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/import/ohdear/runs/{run}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.get = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.head = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
const showForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
showForm.get = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
showForm.head = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const ImportOhDearController = { edit, show }

export default ImportOhDearController