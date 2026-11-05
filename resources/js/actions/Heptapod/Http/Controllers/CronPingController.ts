import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults, validateParameters } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
const CronPingController = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CronPingController.url(args, options),
    method: 'get',
})

CronPingController.definition = {
    methods: ["get","post","head"],
    url: '/cron/{uuid}/{phase?}',
} satisfies RouteDefinition<["get","post","head"]>

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingController.url = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            uuid: args[0],
            phase: args[1],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "phase",
    ])

    const parsedArgs = {
        uuid: args.uuid,
        phase: args.phase,
    }

    return CronPingController.definition.url
            .replace('{uuid}', parsedArgs.uuid.toString())
            .replace('{phase?}', parsedArgs.phase?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingController.get = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CronPingController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingController.post = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: CronPingController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingController.head = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CronPingController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
const CronPingControllerForm = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CronPingController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingControllerForm.get = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CronPingController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingControllerForm.post = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: CronPingController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
CronPingControllerForm.head = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CronPingController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

CronPingController.form = CronPingControllerForm

export default CronPingController