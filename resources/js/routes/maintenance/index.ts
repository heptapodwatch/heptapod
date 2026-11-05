import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import series from './series'
/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/maintenance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::index
* @see app/Http/Controllers/MaintenanceController.php:36
* @route '/maintenance'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::store
* @see app/Http/Controllers/MaintenanceController.php:95
* @route '/maintenance'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/maintenance',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::store
* @see app/Http/Controllers/MaintenanceController.php:95
* @route '/maintenance'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::store
* @see app/Http/Controllers/MaintenanceController.php:95
* @route '/maintenance'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::store
* @see app/Http/Controllers/MaintenanceController.php:95
* @route '/maintenance'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::store
* @see app/Http/Controllers/MaintenanceController.php:95
* @route '/maintenance'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::update
* @see app/Http/Controllers/MaintenanceController.php:106
* @route '/maintenance/{window}'
*/
export const update = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/maintenance/{window}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::update
* @see app/Http/Controllers/MaintenanceController.php:106
* @route '/maintenance/{window}'
*/
update.url = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { window: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { window: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            window: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        window: typeof args.window === 'object'
        ? args.window.id
        : args.window,
    }

    return update.definition.url
            .replace('{window}', parsedArgs.window.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::update
* @see app/Http/Controllers/MaintenanceController.php:106
* @route '/maintenance/{window}'
*/
update.put = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::update
* @see app/Http/Controllers/MaintenanceController.php:106
* @route '/maintenance/{window}'
*/
const updateForm = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::update
* @see app/Http/Controllers/MaintenanceController.php:106
* @route '/maintenance/{window}'
*/
updateForm.put = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\MaintenanceController::destroy
* @see app/Http/Controllers/MaintenanceController.php:129
* @route '/maintenance/{window}'
*/
export const destroy = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/maintenance/{window}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::destroy
* @see app/Http/Controllers/MaintenanceController.php:129
* @route '/maintenance/{window}'
*/
destroy.url = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { window: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { window: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            window: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        window: typeof args.window === 'object'
        ? args.window.id
        : args.window,
    }

    return destroy.definition.url
            .replace('{window}', parsedArgs.window.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::destroy
* @see app/Http/Controllers/MaintenanceController.php:129
* @route '/maintenance/{window}'
*/
destroy.delete = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::destroy
* @see app/Http/Controllers/MaintenanceController.php:129
* @route '/maintenance/{window}'
*/
const destroyForm = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::destroy
* @see app/Http/Controllers/MaintenanceController.php:129
* @route '/maintenance/{window}'
*/
destroyForm.delete = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
export const end = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: end.url(args, options),
    method: 'post',
})

end.definition = {
    methods: ["post"],
    url: '/maintenance/{window}/end',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
end.url = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { window: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { window: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            window: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        window: typeof args.window === 'object'
        ? args.window.id
        : args.window,
    }

    return end.definition.url
            .replace('{window}', parsedArgs.window.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
end.post = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: end.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
const endForm = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: end.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EndMaintenanceController::__invoke
* @see app/Http/Controllers/EndMaintenanceController.php:16
* @route '/maintenance/{window}/end'
*/
endForm.post = (args: { window: number | { id: number } } | [window: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: end.url(args, options),
    method: 'post',
})

end.form = endForm

const maintenance = {
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    series: Object.assign(series, series),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    end: Object.assign(end, end),
}

export default maintenance