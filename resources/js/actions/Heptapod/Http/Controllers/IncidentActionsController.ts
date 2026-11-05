import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::update
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
export const update = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/incidents/{incident}/note',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::update
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
update.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::update
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
update.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::update
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
const updateForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::update
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
updateForm.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\IncidentActionsController::store
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
export const store = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/incidents/{incident}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::store
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
store.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::store
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
store.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::store
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
const storeForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::store
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
storeForm.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::destroy
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
export const destroy = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/incidents/{incident}/snooze',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::destroy
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
destroy.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::destroy
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
destroy.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::destroy
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
const destroyForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::destroy
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
destroyForm.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const IncidentActionsController = { update, store, destroy }

export default IncidentActionsController