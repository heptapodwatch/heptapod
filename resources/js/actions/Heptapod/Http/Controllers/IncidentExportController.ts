import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
const IncidentExportController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentExportController.url(options),
    method: 'get',
})

IncidentExportController.definition = {
    methods: ["get","head"],
    url: '/incidents/export',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
IncidentExportController.url = (options?: RouteQueryOptions) => {
    return IncidentExportController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
IncidentExportController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentExportController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
IncidentExportController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IncidentExportController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
const IncidentExportControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
IncidentExportControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
IncidentExportControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentExportController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IncidentExportController.form = IncidentExportControllerForm

export default IncidentExportController