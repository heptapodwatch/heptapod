import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
const SiteProbeController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SiteProbeController.url(options),
    method: 'post',
})

SiteProbeController.definition = {
    methods: ["post"],
    url: '/sites/probe',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
SiteProbeController.url = (options?: RouteQueryOptions) => {
    return SiteProbeController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
SiteProbeController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SiteProbeController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
const SiteProbeControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SiteProbeController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
SiteProbeControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SiteProbeController.url(options),
    method: 'post',
})

SiteProbeController.form = SiteProbeControllerForm

export default SiteProbeController