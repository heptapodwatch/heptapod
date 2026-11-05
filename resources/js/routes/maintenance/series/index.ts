import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::update
* @see app/Http/Controllers/MaintenanceSeriesController.php:21
* @route '/maintenance/series/{series}'
*/
export const update = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/maintenance/series/{series}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::update
* @see app/Http/Controllers/MaintenanceSeriesController.php:21
* @route '/maintenance/series/{series}'
*/
update.url = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { series: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { series: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            series: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        series: typeof args.series === 'object'
        ? args.series.id
        : args.series,
    }

    return update.definition.url
            .replace('{series}', parsedArgs.series.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::update
* @see app/Http/Controllers/MaintenanceSeriesController.php:21
* @route '/maintenance/series/{series}'
*/
update.put = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::update
* @see app/Http/Controllers/MaintenanceSeriesController.php:21
* @route '/maintenance/series/{series}'
*/
const updateForm = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::update
* @see app/Http/Controllers/MaintenanceSeriesController.php:21
* @route '/maintenance/series/{series}'
*/
updateForm.put = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::destroy
* @see app/Http/Controllers/MaintenanceSeriesController.php:57
* @route '/maintenance/series/{series}'
*/
export const destroy = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/maintenance/series/{series}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::destroy
* @see app/Http/Controllers/MaintenanceSeriesController.php:57
* @route '/maintenance/series/{series}'
*/
destroy.url = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { series: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { series: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            series: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        series: typeof args.series === 'object'
        ? args.series.id
        : args.series,
    }

    return destroy.definition.url
            .replace('{series}', parsedArgs.series.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::destroy
* @see app/Http/Controllers/MaintenanceSeriesController.php:57
* @route '/maintenance/series/{series}'
*/
destroy.delete = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::destroy
* @see app/Http/Controllers/MaintenanceSeriesController.php:57
* @route '/maintenance/series/{series}'
*/
const destroyForm = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceSeriesController::destroy
* @see app/Http/Controllers/MaintenanceSeriesController.php:57
* @route '/maintenance/series/{series}'
*/
destroyForm.delete = (args: { series: number | { id: number } } | [series: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const series = {
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
}

export default series