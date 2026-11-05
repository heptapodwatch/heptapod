import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::store
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
export const store = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/sites/{site}/pause',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::store
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
store.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return store.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::store
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
store.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::store
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
const storeForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::store
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
storeForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::destroy
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
export const destroy = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(args, options),
    method: 'post',
})

destroy.definition = {
    methods: ["post"],
    url: '/sites/{site}/resume',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::destroy
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
destroy.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return destroy.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::destroy
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
destroy.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: destroy.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::destroy
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
const destroyForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::destroy
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
destroyForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, options),
    method: 'post',
})

destroy.form = destroyForm

const SiteMonitoringController = { store, destroy }

export default SiteMonitoringController