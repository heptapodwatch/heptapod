import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
const IncidentExportController = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentExportController.url(args, options),
    method: 'get',
})

IncidentExportController.definition = {
    methods: ["get","head"],
    url: '/incidents/export.{format}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
IncidentExportController.url = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { format: args }
    }

    if (Array.isArray(args)) {
        args = {
            format: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        format: args.format,
    }

    return IncidentExportController.definition.url
            .replace('{format}', parsedArgs.format.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
IncidentExportController.get = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
IncidentExportController.head = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IncidentExportController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
const IncidentExportControllerForm = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
IncidentExportControllerForm.get = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:21
* @route '/incidents/export.{format}'
*/
IncidentExportControllerForm.head = (args: { format: string | number } | [format: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IncidentExportController.form = IncidentExportControllerForm

export default IncidentExportController