import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
const IncidentDetailController = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentDetailController.url(args, options),
    method: 'get',
})

IncidentDetailController.definition = {
    methods: ["get","head"],
    url: '/incidents/{incident}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
IncidentDetailController.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { incident: args }
    }

    if (Array.isArray(args)) {
        args = {
            incident: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        incident: args.incident,
    }

    return IncidentDetailController.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
IncidentDetailController.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IncidentDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
IncidentDetailController.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IncidentDetailController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
const IncidentDetailControllerForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
IncidentDetailControllerForm.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
IncidentDetailControllerForm.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IncidentDetailController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IncidentDetailController.form = IncidentDetailControllerForm

export default IncidentDetailController