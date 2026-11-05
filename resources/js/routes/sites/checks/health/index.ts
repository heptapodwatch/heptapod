import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
export const probe = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: probe.url(args, options),
    method: 'post',
})

probe.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/health/probe',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
probe.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return probe.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
probe.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: probe.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
const probeForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: probe.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
probeForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: probe.url(args, options),
    method: 'post',
})

probe.form = probeForm

const health = {
    probe: Object.assign(probe, probe),
}

export default health