import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
export const show = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/internal/incidents/{incident}/note',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
show.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
show.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
show.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
const showForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
showForm.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::show
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
showForm.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::update
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
export const update = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/internal/incidents/{incident}/note',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::update
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
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
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::update
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
update.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::update
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
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
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::update
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
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

const IncidentNoteController = { show, update }

export default IncidentNoteController