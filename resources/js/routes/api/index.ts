import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import internal from './internal'
/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
export const getOpenApiDocument = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getOpenApiDocument.url(options),
    method: 'get',
})

getOpenApiDocument.definition = {
    methods: ["get","head"],
    url: '/api/openapi.json',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
getOpenApiDocument.url = (options?: RouteQueryOptions) => {
    return getOpenApiDocument.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
getOpenApiDocument.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getOpenApiDocument.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
getOpenApiDocument.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getOpenApiDocument.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
const getOpenApiDocumentForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getOpenApiDocument.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
getOpenApiDocumentForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getOpenApiDocument.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
getOpenApiDocumentForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getOpenApiDocument.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getOpenApiDocument.form = getOpenApiDocumentForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
export const listMaintenancePeriods = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listMaintenancePeriods.url(args, options),
    method: 'get',
})

listMaintenancePeriods.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/maintenance-periods',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
listMaintenancePeriods.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return listMaintenancePeriods.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
listMaintenancePeriods.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listMaintenancePeriods.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
listMaintenancePeriods.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listMaintenancePeriods.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
const listMaintenancePeriodsForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMaintenancePeriods.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
listMaintenancePeriodsForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMaintenancePeriods.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::listMaintenancePeriods
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:32
* @route '/api/monitors/{monitor}/maintenance-periods'
*/
listMaintenancePeriodsForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMaintenancePeriods.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listMaintenancePeriods.form = listMaintenancePeriodsForm

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:21
* @route '/api/monitors/{monitor}/start-maintenance'
*/
export const startMaintenance = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startMaintenance.url(args, options),
    method: 'post',
})

startMaintenance.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/start-maintenance',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:21
* @route '/api/monitors/{monitor}/start-maintenance'
*/
startMaintenance.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return startMaintenance.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:21
* @route '/api/monitors/{monitor}/start-maintenance'
*/
startMaintenance.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: startMaintenance.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:21
* @route '/api/monitors/{monitor}/start-maintenance'
*/
const startMaintenanceForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: startMaintenance.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StartMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StartMaintenanceController.php:21
* @route '/api/monitors/{monitor}/start-maintenance'
*/
startMaintenanceForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: startMaintenance.url(args, options),
    method: 'post',
})

startMaintenance.form = startMaintenanceForm

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
export const stopMaintenance = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopMaintenance.url(args, options),
    method: 'post',
})

stopMaintenance.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/stop-maintenance',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
stopMaintenance.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stopMaintenance.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
stopMaintenance.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopMaintenance.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
const stopMaintenanceForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: stopMaintenance.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\StopMaintenanceController::__invoke
* @see app/Http/Controllers/Api/StopMaintenanceController.php:19
* @route '/api/monitors/{monitor}/stop-maintenance'
*/
stopMaintenanceForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: stopMaintenance.url(args, options),
    method: 'post',
})

stopMaintenance.form = stopMaintenanceForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::createMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
export const createMaintenancePeriod = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createMaintenancePeriod.url(options),
    method: 'post',
})

createMaintenancePeriod.definition = {
    methods: ["post"],
    url: '/api/maintenance-periods',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::createMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
createMaintenancePeriod.url = (options?: RouteQueryOptions) => {
    return createMaintenancePeriod.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::createMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
createMaintenancePeriod.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createMaintenancePeriod.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::createMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
const createMaintenancePeriodForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createMaintenancePeriod.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::createMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:74
* @route '/api/maintenance-periods'
*/
createMaintenancePeriodForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createMaintenancePeriod.url(options),
    method: 'post',
})

createMaintenancePeriod.form = createMaintenancePeriodForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::updateMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
export const updateMaintenancePeriod = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMaintenancePeriod.url(args, options),
    method: 'put',
})

updateMaintenancePeriod.definition = {
    methods: ["put"],
    url: '/api/maintenance-periods/{maintenancePeriod}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::updateMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
updateMaintenancePeriod.url = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return updateMaintenancePeriod.definition.url
            .replace('{maintenancePeriod}', parsedArgs.maintenancePeriod.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::updateMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
updateMaintenancePeriod.put = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMaintenancePeriod.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::updateMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
const updateMaintenancePeriodForm = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMaintenancePeriod.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::updateMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:98
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
updateMaintenancePeriodForm.put = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMaintenancePeriod.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateMaintenancePeriod.form = updateMaintenancePeriodForm

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::deleteMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
export const deleteMaintenancePeriod = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMaintenancePeriod.url(args, options),
    method: 'delete',
})

deleteMaintenancePeriod.definition = {
    methods: ["delete"],
    url: '/api/maintenance-periods/{maintenancePeriod}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::deleteMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
deleteMaintenancePeriod.url = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return deleteMaintenancePeriod.definition.url
            .replace('{maintenancePeriod}', parsedArgs.maintenancePeriod.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::deleteMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
deleteMaintenancePeriod.delete = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMaintenancePeriod.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::deleteMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
const deleteMaintenancePeriodForm = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteMaintenancePeriod.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MaintenancePeriodController::deleteMaintenancePeriod
* @see app/Http/Controllers/Api/MaintenancePeriodController.php:116
* @route '/api/maintenance-periods/{maintenancePeriod}'
*/
deleteMaintenancePeriodForm.delete = (args: { maintenancePeriod: number | { id: number } } | [maintenancePeriod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteMaintenancePeriod.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

deleteMaintenancePeriod.form = deleteMaintenancePeriodForm

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
export const listCronChecks = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listCronChecks.url(args, options),
    method: 'get',
})

listCronChecks.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/cron-checks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
listCronChecks.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return listCronChecks.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
listCronChecks.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listCronChecks.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
listCronChecks.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listCronChecks.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
const listCronChecksForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCronChecks.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
listCronChecksForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCronChecks.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::listCronChecks
* @see app/Http/Controllers/Api/CronTaskController.php:25
* @route '/api/monitors/{monitor}/cron-checks'
*/
listCronChecksForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCronChecks.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listCronChecks.form = listCronChecksForm

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::createCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
export const createCronCheck = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createCronCheck.url(args, options),
    method: 'post',
})

createCronCheck.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/cron-checks',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::createCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
createCronCheck.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return createCronCheck.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::createCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
createCronCheck.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::createCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
const createCronCheckForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::createCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:32
* @route '/api/monitors/{monitor}/cron-checks'
*/
createCronCheckForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createCronCheck.url(args, options),
    method: 'post',
})

createCronCheck.form = createCronCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:22
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
export const syncCronChecks = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: syncCronChecks.url(args, options),
    method: 'post',
})

syncCronChecks.definition = {
    methods: ["post"],
    url: '/api/monitors/{monitor}/cron-checks/sync',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:22
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
syncCronChecks.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return syncCronChecks.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:22
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
syncCronChecks.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: syncCronChecks.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:22
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
const syncCronChecksForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: syncCronChecks.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SyncCronTasksController::__invoke
* @see app/Http/Controllers/Api/SyncCronTasksController.php:22
* @route '/api/monitors/{monitor}/cron-checks/sync'
*/
syncCronChecksForm.post = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: syncCronChecks.url(args, options),
    method: 'post',
})

syncCronChecks.form = syncCronChecksForm

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::updateCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
export const updateCronCheck = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateCronCheck.url(args, options),
    method: 'put',
})

updateCronCheck.definition = {
    methods: ["put"],
    url: '/api/cron-checks/{cronTask}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::updateCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
updateCronCheck.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return updateCronCheck.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::updateCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
updateCronCheck.put = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateCronCheck.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::updateCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
const updateCronCheckForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateCronCheck.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::updateCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:42
* @route '/api/cron-checks/{cronTask}'
*/
updateCronCheckForm.put = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateCronCheck.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateCronCheck.form = updateCronCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::deleteCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
export const deleteCronCheck = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteCronCheck.url(args, options),
    method: 'delete',
})

deleteCronCheck.definition = {
    methods: ["delete"],
    url: '/api/cron-checks/{cronTask}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::deleteCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
deleteCronCheck.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return deleteCronCheck.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::deleteCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
deleteCronCheck.delete = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteCronCheck.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::deleteCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
const deleteCronCheckForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteCronCheck.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\CronTaskController::deleteCronCheck
* @see app/Http/Controllers/Api/CronTaskController.php:51
* @route '/api/cron-checks/{cronTask}'
*/
deleteCronCheckForm.delete = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteCronCheck.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

deleteCronCheck.form = deleteCronCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:22
* @route '/api/cron-checks/{cronTask}/snooze'
*/
export const snoozeCronCheck = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeCronCheck.url(args, options),
    method: 'post',
})

snoozeCronCheck.definition = {
    methods: ["post"],
    url: '/api/cron-checks/{cronTask}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:22
* @route '/api/cron-checks/{cronTask}/snooze'
*/
snoozeCronCheck.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return snoozeCronCheck.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:22
* @route '/api/cron-checks/{cronTask}/snooze'
*/
snoozeCronCheck.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:22
* @route '/api/cron-checks/{cronTask}/snooze'
*/
const snoozeCronCheckForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:22
* @route '/api/cron-checks/{cronTask}/snooze'
*/
snoozeCronCheckForm.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeCronCheck.url(args, options),
    method: 'post',
})

snoozeCronCheck.form = snoozeCronCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:21
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
export const unsnoozeCronCheck = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnoozeCronCheck.url(args, options),
    method: 'post',
})

unsnoozeCronCheck.definition = {
    methods: ["post"],
    url: '/api/cron-checks/{cronTask}/unsnooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:21
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
unsnoozeCronCheck.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return unsnoozeCronCheck.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:21
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
unsnoozeCronCheck.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnoozeCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:21
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
const unsnoozeCronCheckForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeCronCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:21
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
unsnoozeCronCheckForm.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeCronCheck.url(args, options),
    method: 'post',
})

unsnoozeCronCheck.form = unsnoozeCronCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
export const getMe = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMe.url(options),
    method: 'get',
})

getMe.definition = {
    methods: ["get","head"],
    url: '/api/me',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
getMe.url = (options?: RouteQueryOptions) => {
    return getMe.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
getMe.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMe.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
getMe.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getMe.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
const getMeForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMe.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
getMeForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMe.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MeController::__invoke
* @see app/Http/Controllers/Api/MeController.php:19
* @route '/api/me'
*/
getMeForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMe.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getMe.form = getMeForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
export const listMonitors = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listMonitors.url(options),
    method: 'get',
})

listMonitors.definition = {
    methods: ["get","head"],
    url: '/api/monitors',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
listMonitors.url = (options?: RouteQueryOptions) => {
    return listMonitors.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
listMonitors.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listMonitors.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
listMonitors.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listMonitors.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
const listMonitorsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMonitors.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
listMonitorsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMonitors.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::listMonitors
* @see app/Http/Controllers/Api/MonitorController.php:34
* @route '/api/monitors'
*/
listMonitorsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listMonitors.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listMonitors.form = listMonitorsForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
export const getMonitorByUrl = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMonitorByUrl.url(args, options),
    method: 'get',
})

getMonitorByUrl.definition = {
    methods: ["get","head"],
    url: '/api/monitors/url/{url}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
getMonitorByUrl.url = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { url: args }
    }

    if (Array.isArray(args)) {
        args = {
            url: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        url: args.url,
    }

    return getMonitorByUrl.definition.url
            .replace('{url}', parsedArgs.url.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
getMonitorByUrl.get = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMonitorByUrl.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
getMonitorByUrl.head = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getMonitorByUrl.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
const getMonitorByUrlForm = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitorByUrl.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
getMonitorByUrlForm.get = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitorByUrl.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:18
* @route '/api/monitors/url/{url}'
*/
getMonitorByUrlForm.head = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitorByUrl.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getMonitorByUrl.form = getMonitorByUrlForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
export const getMonitor = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMonitor.url(args, options),
    method: 'get',
})

getMonitor.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
getMonitor.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return getMonitor.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
getMonitor.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getMonitor.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
getMonitor.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getMonitor.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
const getMonitorForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitor.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
getMonitorForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitor.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::getMonitor
* @see app/Http/Controllers/Api/MonitorController.php:52
* @route '/api/monitors/{monitor}'
*/
getMonitorForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getMonitor.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getMonitor.form = getMonitorForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::createMonitor
* @see app/Http/Controllers/Api/MonitorController.php:61
* @route '/api/monitors'
*/
export const createMonitor = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createMonitor.url(options),
    method: 'post',
})

createMonitor.definition = {
    methods: ["post"],
    url: '/api/monitors',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::createMonitor
* @see app/Http/Controllers/Api/MonitorController.php:61
* @route '/api/monitors'
*/
createMonitor.url = (options?: RouteQueryOptions) => {
    return createMonitor.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::createMonitor
* @see app/Http/Controllers/Api/MonitorController.php:61
* @route '/api/monitors'
*/
createMonitor.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createMonitor.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::createMonitor
* @see app/Http/Controllers/Api/MonitorController.php:61
* @route '/api/monitors'
*/
const createMonitorForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createMonitor.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::createMonitor
* @see app/Http/Controllers/Api/MonitorController.php:61
* @route '/api/monitors'
*/
createMonitorForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createMonitor.url(options),
    method: 'post',
})

createMonitor.form = createMonitorForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::updateMonitor
* @see app/Http/Controllers/Api/MonitorController.php:75
* @route '/api/monitors/{monitor}'
*/
export const updateMonitor = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMonitor.url(args, options),
    method: 'put',
})

updateMonitor.definition = {
    methods: ["put"],
    url: '/api/monitors/{monitor}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::updateMonitor
* @see app/Http/Controllers/Api/MonitorController.php:75
* @route '/api/monitors/{monitor}'
*/
updateMonitor.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return updateMonitor.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::updateMonitor
* @see app/Http/Controllers/Api/MonitorController.php:75
* @route '/api/monitors/{monitor}'
*/
updateMonitor.put = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMonitor.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::updateMonitor
* @see app/Http/Controllers/Api/MonitorController.php:75
* @route '/api/monitors/{monitor}'
*/
const updateMonitorForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMonitor.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::updateMonitor
* @see app/Http/Controllers/Api/MonitorController.php:75
* @route '/api/monitors/{monitor}'
*/
updateMonitorForm.put = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMonitor.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateMonitor.form = updateMonitorForm

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::deleteMonitor
* @see app/Http/Controllers/Api/MonitorController.php:87
* @route '/api/monitors/{monitor}'
*/
export const deleteMonitor = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMonitor.url(args, options),
    method: 'delete',
})

deleteMonitor.definition = {
    methods: ["delete"],
    url: '/api/monitors/{monitor}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::deleteMonitor
* @see app/Http/Controllers/Api/MonitorController.php:87
* @route '/api/monitors/{monitor}'
*/
deleteMonitor.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return deleteMonitor.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::deleteMonitor
* @see app/Http/Controllers/Api/MonitorController.php:87
* @route '/api/monitors/{monitor}'
*/
deleteMonitor.delete = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMonitor.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::deleteMonitor
* @see app/Http/Controllers/Api/MonitorController.php:87
* @route '/api/monitors/{monitor}'
*/
const deleteMonitorForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteMonitor.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorController::deleteMonitor
* @see app/Http/Controllers/Api/MonitorController.php:87
* @route '/api/monitors/{monitor}'
*/
deleteMonitorForm.delete = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: deleteMonitor.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

deleteMonitor.form = deleteMonitorForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
export const listIncidents = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listIncidents.url(options),
    method: 'get',
})

listIncidents.definition = {
    methods: ["get","head"],
    url: '/api/incidents',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
listIncidents.url = (options?: RouteQueryOptions) => {
    return listIncidents.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
listIncidents.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listIncidents.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
listIncidents.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listIncidents.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
const listIncidentsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listIncidents.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
listIncidentsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listIncidents.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::listIncidents
* @see app/Http/Controllers/Api/IncidentController.php:42
* @route '/api/incidents'
*/
listIncidentsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listIncidents.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listIncidents.form = listIncidentsForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
export const getIncident = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getIncident.url(args, options),
    method: 'get',
})

getIncident.definition = {
    methods: ["get","head"],
    url: '/api/incidents/{incident}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
getIncident.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { incident: args }
    }

    if (Array.isArray(args)) {
        args = {
            incident: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        incident: args.incident,
    }

    return getIncident.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
getIncident.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getIncident.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
getIncident.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getIncident.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
const getIncidentForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncident.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
getIncidentForm.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncident.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentController::getIncident
* @see app/Http/Controllers/Api/IncidentController.php:105
* @route '/api/incidents/{incident}'
*/
getIncidentForm.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncident.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getIncident.form = getIncidentForm

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
export const listActivity = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listActivity.url(options),
    method: 'get',
})

listActivity.definition = {
    methods: ["get","head"],
    url: '/api/activity',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
listActivity.url = (options?: RouteQueryOptions) => {
    return listActivity.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
listActivity.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listActivity.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
listActivity.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listActivity.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
const listActivityForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listActivity.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
listActivityForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listActivity.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\ActivityController::__invoke
* @see app/Http/Controllers/Api/ActivityController.php:33
* @route '/api/activity'
*/
listActivityForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listActivity.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listActivity.form = listActivityForm

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
export const listUpcoming = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listUpcoming.url(options),
    method: 'get',
})

listUpcoming.definition = {
    methods: ["get","head"],
    url: '/api/upcoming',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
listUpcoming.url = (options?: RouteQueryOptions) => {
    return listUpcoming.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
listUpcoming.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listUpcoming.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
listUpcoming.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listUpcoming.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
const listUpcomingForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listUpcoming.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
listUpcomingForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listUpcoming.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UpcomingController::__invoke
* @see app/Http/Controllers/Api/UpcomingController.php:26
* @route '/api/upcoming'
*/
listUpcomingForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listUpcoming.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listUpcoming.form = listUpcomingForm

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
export const findings = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: findings.url(options),
    method: 'get',
})

findings.definition = {
    methods: ["get","head"],
    url: '/api/findings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
findings.url = (options?: RouteQueryOptions) => {
    return findings.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
findings.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: findings.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
findings.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: findings.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
const findingsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
findingsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\FindingsController::__invoke
* @see app/Http/Controllers/Api/FindingsController.php:41
* @route '/api/findings'
*/
findingsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

findings.form = findingsForm

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
export const getCheckSummary = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getCheckSummary.url(args, options),
    method: 'get',
})

getCheckSummary.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/check-summary/{checkType}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
getCheckSummary.url = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
            checkType: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
        checkType: args.checkType,
    }

    return getCheckSummary.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace('{checkType}', parsedArgs.checkType.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
getCheckSummary.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getCheckSummary.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
getCheckSummary.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getCheckSummary.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
const getCheckSummaryForm = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getCheckSummary.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
getCheckSummaryForm.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getCheckSummary.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:19
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
getCheckSummaryForm.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getCheckSummary.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getCheckSummary.form = getCheckSummaryForm

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
export const listCheckRuns = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listCheckRuns.url(args, options),
    method: 'get',
})

listCheckRuns.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/checks/{checkType}/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
listCheckRuns.url = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
            checkType: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
        checkType: args.checkType,
    }

    return listCheckRuns.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace('{checkType}', parsedArgs.checkType.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
listCheckRuns.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listCheckRuns.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
listCheckRuns.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listCheckRuns.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
const listCheckRunsForm = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCheckRuns.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
listCheckRunsForm.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCheckRuns.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:21
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
listCheckRunsForm.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listCheckRuns.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listCheckRuns.form = listCheckRunsForm

/**
* @see \Heptapod\Http\Controllers\Api\EnableCheckController::__invoke
* @see app/Http/Controllers/Api/EnableCheckController.php:17
* @route '/api/checks/{check}/enable'
*/
export const enableCheck = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableCheck.url(args, options),
    method: 'post',
})

enableCheck.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\EnableCheckController::__invoke
* @see app/Http/Controllers/Api/EnableCheckController.php:17
* @route '/api/checks/{check}/enable'
*/
enableCheck.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return enableCheck.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\EnableCheckController::__invoke
* @see app/Http/Controllers/Api/EnableCheckController.php:17
* @route '/api/checks/{check}/enable'
*/
enableCheck.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enableCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\EnableCheckController::__invoke
* @see app/Http/Controllers/Api/EnableCheckController.php:17
* @route '/api/checks/{check}/enable'
*/
const enableCheckForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enableCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\EnableCheckController::__invoke
* @see app/Http/Controllers/Api/EnableCheckController.php:17
* @route '/api/checks/{check}/enable'
*/
enableCheckForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enableCheck.url(args, options),
    method: 'post',
})

enableCheck.form = enableCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:17
* @route '/api/checks/{check}/disable'
*/
export const disableCheck = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disableCheck.url(args, options),
    method: 'post',
})

disableCheck.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/disable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:17
* @route '/api/checks/{check}/disable'
*/
disableCheck.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return disableCheck.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:17
* @route '/api/checks/{check}/disable'
*/
disableCheck.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: disableCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:17
* @route '/api/checks/{check}/disable'
*/
const disableCheckForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: disableCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:17
* @route '/api/checks/{check}/disable'
*/
disableCheckForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: disableCheck.url(args, options),
    method: 'post',
})

disableCheck.form = disableCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\RunController::createCheckRun
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
export const createCheckRun = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createCheckRun.url(args, options),
    method: 'post',
})

createCheckRun.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/runs',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\RunController::createCheckRun
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
createCheckRun.url = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: args.check,
    }

    return createCheckRun.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RunController::createCheckRun
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
createCheckRun.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createCheckRun.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::createCheckRun
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
const createCheckRunForm = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createCheckRun.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::createCheckRun
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
createCheckRunForm.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createCheckRun.url(args, options),
    method: 'post',
})

createCheckRun.form = createCheckRunForm

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
export const getRun = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getRun.url(args, options),
    method: 'get',
})

getRun.definition = {
    methods: ["get","head"],
    url: '/api/runs/{attemptRef}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
getRun.url = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { attemptRef: args }
    }

    if (Array.isArray(args)) {
        args = {
            attemptRef: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        attemptRef: args.attemptRef,
    }

    return getRun.definition.url
            .replace('{attemptRef}', parsedArgs.attemptRef.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
getRun.get = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getRun.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
getRun.head = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getRun.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
const getRunForm = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getRun.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
getRunForm.get = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getRun.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::getRun
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
getRunForm.head = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getRun.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getRun.form = getRunForm

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:21
* @route '/api/checks/{check}/request-run'
*/
export const requestCheckRun = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestCheckRun.url(args, options),
    method: 'post',
})

requestCheckRun.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/request-run',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:21
* @route '/api/checks/{check}/request-run'
*/
requestCheckRun.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return requestCheckRun.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:21
* @route '/api/checks/{check}/request-run'
*/
requestCheckRun.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestCheckRun.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:21
* @route '/api/checks/{check}/request-run'
*/
const requestCheckRunForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: requestCheckRun.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:21
* @route '/api/checks/{check}/request-run'
*/
requestCheckRunForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: requestCheckRun.url(args, options),
    method: 'post',
})

requestCheckRun.form = requestCheckRunForm

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
export const snoozeCheck = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeCheck.url(args, options),
    method: 'post',
})

snoozeCheck.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
snoozeCheck.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return snoozeCheck.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
snoozeCheck.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
const snoozeCheckForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
snoozeCheckForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeCheck.url(args, options),
    method: 'post',
})

snoozeCheck.form = snoozeCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:21
* @route '/api/checks/{check}/unsnooze'
*/
export const unsnoozeCheck = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnoozeCheck.url(args, options),
    method: 'post',
})

unsnoozeCheck.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/unsnooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:21
* @route '/api/checks/{check}/unsnooze'
*/
unsnoozeCheck.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return unsnoozeCheck.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:21
* @route '/api/checks/{check}/unsnooze'
*/
unsnoozeCheck.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnoozeCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:21
* @route '/api/checks/{check}/unsnooze'
*/
const unsnoozeCheckForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeCheck.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:21
* @route '/api/checks/{check}/unsnooze'
*/
unsnoozeCheckForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeCheck.url(args, options),
    method: 'post',
})

unsnoozeCheck.form = unsnoozeCheckForm

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
export const getUptime = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getUptime.url(args, options),
    method: 'get',
})

getUptime.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/uptime',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
getUptime.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return getUptime.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
getUptime.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getUptime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
getUptime.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getUptime.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
const getUptimeForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getUptime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
getUptimeForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getUptime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
getUptimeForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getUptime.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getUptime.form = getUptimeForm

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
export const httpUptimeMetrics = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: httpUptimeMetrics.url(args, options),
    method: 'get',
})

httpUptimeMetrics.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/http-uptime-metrics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
httpUptimeMetrics.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return httpUptimeMetrics.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
httpUptimeMetrics.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: httpUptimeMetrics.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
httpUptimeMetrics.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: httpUptimeMetrics.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
const httpUptimeMetricsForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: httpUptimeMetrics.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
httpUptimeMetricsForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: httpUptimeMetrics.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
httpUptimeMetricsForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: httpUptimeMetrics.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

httpUptimeMetrics.form = httpUptimeMetricsForm

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
export const getDowntime = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getDowntime.url(args, options),
    method: 'get',
})

getDowntime.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/downtime',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
getDowntime.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return getDowntime.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
getDowntime.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getDowntime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
getDowntime.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getDowntime.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
const getDowntimeForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getDowntime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
getDowntimeForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getDowntime.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:21
* @route '/api/monitors/{monitor}/downtime'
*/
getDowntimeForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getDowntime.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getDowntime.form = getDowntimeForm

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
export const listTags = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listTags.url(options),
    method: 'get',
})

listTags.definition = {
    methods: ["get","head"],
    url: '/api/tags',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
listTags.url = (options?: RouteQueryOptions) => {
    return listTags.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
listTags.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listTags.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
listTags.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listTags.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
const listTagsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listTags.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
listTagsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listTags.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::listTags
* @see app/Http/Controllers/Api/TagController.php:28
* @route '/api/tags'
*/
listTagsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: listTags.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

listTags.form = listTagsForm

/**
* @see \Heptapod\Http\Controllers\Api\TagController::createTag
* @see app/Http/Controllers/Api/TagController.php:47
* @route '/api/tags'
*/
export const createTag = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createTag.url(options),
    method: 'post',
})

createTag.definition = {
    methods: ["post"],
    url: '/api/tags',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\TagController::createTag
* @see app/Http/Controllers/Api/TagController.php:47
* @route '/api/tags'
*/
createTag.url = (options?: RouteQueryOptions) => {
    return createTag.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\TagController::createTag
* @see app/Http/Controllers/Api/TagController.php:47
* @route '/api/tags'
*/
createTag.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: createTag.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::createTag
* @see app/Http/Controllers/Api/TagController.php:47
* @route '/api/tags'
*/
const createTagForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createTag.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::createTag
* @see app/Http/Controllers/Api/TagController.php:47
* @route '/api/tags'
*/
createTagForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: createTag.url(options),
    method: 'post',
})

createTag.form = createTagForm

const api = {
    getOpenApiDocument: Object.assign(getOpenApiDocument, getOpenApiDocument),
    listMaintenancePeriods: Object.assign(listMaintenancePeriods, listMaintenancePeriods),
    startMaintenance: Object.assign(startMaintenance, startMaintenance),
    stopMaintenance: Object.assign(stopMaintenance, stopMaintenance),
    createMaintenancePeriod: Object.assign(createMaintenancePeriod, createMaintenancePeriod),
    updateMaintenancePeriod: Object.assign(updateMaintenancePeriod, updateMaintenancePeriod),
    deleteMaintenancePeriod: Object.assign(deleteMaintenancePeriod, deleteMaintenancePeriod),
    listCronChecks: Object.assign(listCronChecks, listCronChecks),
    createCronCheck: Object.assign(createCronCheck, createCronCheck),
    syncCronChecks: Object.assign(syncCronChecks, syncCronChecks),
    updateCronCheck: Object.assign(updateCronCheck, updateCronCheck),
    deleteCronCheck: Object.assign(deleteCronCheck, deleteCronCheck),
    snoozeCronCheck: Object.assign(snoozeCronCheck, snoozeCronCheck),
    unsnoozeCronCheck: Object.assign(unsnoozeCronCheck, unsnoozeCronCheck),
    getMe: Object.assign(getMe, getMe),
    internal: Object.assign(internal, internal),
    listMonitors: Object.assign(listMonitors, listMonitors),
    getMonitorByUrl: Object.assign(getMonitorByUrl, getMonitorByUrl),
    getMonitor: Object.assign(getMonitor, getMonitor),
    createMonitor: Object.assign(createMonitor, createMonitor),
    updateMonitor: Object.assign(updateMonitor, updateMonitor),
    deleteMonitor: Object.assign(deleteMonitor, deleteMonitor),
    listIncidents: Object.assign(listIncidents, listIncidents),
    getIncident: Object.assign(getIncident, getIncident),
    listActivity: Object.assign(listActivity, listActivity),
    listUpcoming: Object.assign(listUpcoming, listUpcoming),
    findings: Object.assign(findings, findings),
    getCheckSummary: Object.assign(getCheckSummary, getCheckSummary),
    listCheckRuns: Object.assign(listCheckRuns, listCheckRuns),
    enableCheck: Object.assign(enableCheck, enableCheck),
    disableCheck: Object.assign(disableCheck, disableCheck),
    createCheckRun: Object.assign(createCheckRun, createCheckRun),
    getRun: Object.assign(getRun, getRun),
    requestCheckRun: Object.assign(requestCheckRun, requestCheckRun),
    snoozeCheck: Object.assign(snoozeCheck, snoozeCheck),
    unsnoozeCheck: Object.assign(unsnoozeCheck, unsnoozeCheck),
    getUptime: Object.assign(getUptime, getUptime),
    httpUptimeMetrics: Object.assign(httpUptimeMetrics, httpUptimeMetrics),
    getDowntime: Object.assign(getDowntime, getDowntime),
    listTags: Object.assign(listTags, listTags),
    createTag: Object.assign(createTag, createTag),
}

export default api