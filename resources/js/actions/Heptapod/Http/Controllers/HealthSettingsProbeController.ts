import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
const HealthSettingsProbeController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: HealthSettingsProbeController.url(args, options),
    method: 'post',
})

HealthSettingsProbeController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/health/probe',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
HealthSettingsProbeController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return HealthSettingsProbeController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
HealthSettingsProbeController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: HealthSettingsProbeController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
const HealthSettingsProbeControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: HealthSettingsProbeController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\HealthSettingsProbeController::__invoke
* @see app/Http/Controllers/HealthSettingsProbeController.php:18
* @route '/sites/{site}/checks/{check}/health/probe'
*/
HealthSettingsProbeControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: HealthSettingsProbeController.url(args, options),
    method: 'post',
})

HealthSettingsProbeController.form = HealthSettingsProbeControllerForm

export default HealthSettingsProbeController