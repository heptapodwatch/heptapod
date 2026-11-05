import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults, validateParameters } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
export const ping = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ping.url(args, options),
    method: 'get',
})

ping.definition = {
    methods: ["get","post","head"],
    url: '/cron/{uuid}/{phase?}',
} satisfies RouteDefinition<["get","post","head"]>

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
ping.url = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions) => {
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

    return ping.definition.url
            .replace('{uuid}', parsedArgs.uuid.toString())
            .replace('{phase?}', parsedArgs.phase?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
ping.get = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ping.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
ping.post = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ping.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
ping.head = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ping.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
const pingForm = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ping.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
pingForm.get = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ping.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
pingForm.post = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ping.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CronPingController::__invoke
* @see app/Http/Controllers/CronPingController.php:17
* @route '/cron/{uuid}/{phase?}'
*/
pingForm.head = (args: { uuid: string | number, phase?: string | number } | [uuid: string | number, phase: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ping.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ping.form = pingForm

const cron = {
    ping: Object.assign(ping, ping),
}

export default cron