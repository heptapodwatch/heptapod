import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
export const index = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/cron-checks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
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
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
index.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
index.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
const indexForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
indexForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::index
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
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
* @see \Heptapod\Http\Controllers\Api\CronTaskController::create
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
export const create = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: create.url(args, options),
    method: 'post',
})

create.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/cron-checks',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::create
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
create.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return create.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::create
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
create.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: create.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::create
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
const createForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: create.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::create
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
createForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: create.url(args, options),
    method: 'post',
})

create.form = createForm

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::update
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
export const update = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/cron-checks/{cronTask}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::update
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
update.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { cronTask: args }
    }

    if (Array.isArray(args)) {
        args = {
            cronTask: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        cronTask: args.cronTask,
    }

    return update.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::update
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
update.put = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::update
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
const updateForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::update
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
updateForm.put = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\Api\CronTaskController::destroy
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
export const destroy = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/cron-checks/{cronTask}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::destroy
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
destroy.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { cronTask: args }
    }

    if (Array.isArray(args)) {
        args = {
            cronTask: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        cronTask: args.cronTask,
    }

    return destroy.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::destroy
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
destroy.delete = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::destroy
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
const destroyForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::destroy
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
destroyForm.delete = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const CronTaskController = { index, create, update, destroy }

export default CronTaskController