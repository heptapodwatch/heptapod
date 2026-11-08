import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
export const index = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/maintenance-periods',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
index.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { monitor: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { monitor: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
    }

    return index.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
index.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
index.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
const indexForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
indexForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::index
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
indexForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::store
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/maintenance-periods',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::store
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::store
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::store
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::store
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::update
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
export const update = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/maintenance-periods/{maintenancePeriod}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::update
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
update.url = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { maintenancePeriod: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { maintenancePeriod: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            maintenancePeriod: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        maintenancePeriod: typeof args.maintenancePeriod === 'object'
        ? args.maintenancePeriod.id
        : args.maintenancePeriod,
    }

    return update.definition.url
            .replace('{maintenancePeriod}', parsedArgs.maintenancePeriod.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::update
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
update.put = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::update
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
const updateForm = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::update
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
updateForm.put = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::destroy
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
export const destroy = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/maintenance-periods/{maintenancePeriod}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::destroy
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
destroy.url = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { maintenancePeriod: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { maintenancePeriod: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            maintenancePeriod: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        maintenancePeriod: typeof args.maintenancePeriod === 'object'
        ? args.maintenancePeriod.id
        : args.maintenancePeriod,
    }

    return destroy.definition.url
            .replace('{maintenancePeriod}', parsedArgs.maintenancePeriod.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::destroy
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
destroy.delete = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::destroy
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
const destroyForm = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::destroy
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
destroyForm.delete = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const MaintenancePeriodController = { index, store, update, destroy }

export default MaintenancePeriodController