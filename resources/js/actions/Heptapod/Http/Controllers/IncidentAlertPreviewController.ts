import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
const IncidentAlertPreviewController = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentAlertPreviewController.url(args, options),
    method: 'get',
})

IncidentAlertPreviewController.definition = {
    methods: ["get","head"],
    url: '/incidents/{incident}/alerts/{event}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
IncidentAlertPreviewController.url = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            incident: args[0],
            event: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        incident: args.incident,
        event: args.event,
    }

    return IncidentAlertPreviewController.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace('{event}', parsedArgs.event.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
IncidentAlertPreviewController.get = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentAlertPreviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
IncidentAlertPreviewController.head = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IncidentAlertPreviewController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
const IncidentAlertPreviewControllerForm = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentAlertPreviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
IncidentAlertPreviewControllerForm.get = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentAlertPreviewController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
IncidentAlertPreviewControllerForm.head = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentAlertPreviewController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IncidentAlertPreviewController.form = IncidentAlertPreviewControllerForm

export default IncidentAlertPreviewController