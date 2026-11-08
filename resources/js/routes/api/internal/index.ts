import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
export const getIncidentNote = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getIncidentNote.url(args, options),
    method: 'get',
})

getIncidentNote.definition = {
    methods: ["get","head"],
    url: '/api/internal/incidents/{incident}/note',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
getIncidentNote.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return getIncidentNote.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
getIncidentNote.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: getIncidentNote.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
getIncidentNote.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: getIncidentNote.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
const getIncidentNoteForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncidentNote.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
getIncidentNoteForm.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncidentNote.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::getIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:22
* @route '/api/internal/incidents/{incident}/note'
*/
getIncidentNoteForm.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: getIncidentNote.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

getIncidentNote.form = getIncidentNoteForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::saveIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
export const saveIncidentNote = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: saveIncidentNote.url(args, options),
    method: 'put',
})

saveIncidentNote.definition = {
    methods: ["put"],
    url: '/api/internal/incidents/{incident}/note',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::saveIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
saveIncidentNote.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return saveIncidentNote.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::saveIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
saveIncidentNote.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: saveIncidentNote.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::saveIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
const saveIncidentNoteForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: saveIncidentNote.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentNoteController::saveIncidentNote
* @see app/Http/Controllers/Api/IncidentNoteController.php:30
* @route '/api/internal/incidents/{incident}/note'
*/
saveIncidentNoteForm.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: saveIncidentNote.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

saveIncidentNote.form = saveIncidentNoteForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::snoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:21
* @route '/api/internal/incidents/{incident}/snooze'
*/
export const snoozeIncident = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeIncident.url(args, options),
    method: 'post',
})

snoozeIncident.definition = {
    methods: ["post"],
    url: '/api/internal/incidents/{incident}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::snoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:21
* @route '/api/internal/incidents/{incident}/snooze'
*/
snoozeIncident.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return snoozeIncident.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::snoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:21
* @route '/api/internal/incidents/{incident}/snooze'
*/
snoozeIncident.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snoozeIncident.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::snoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:21
* @route '/api/internal/incidents/{incident}/snooze'
*/
const snoozeIncidentForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeIncident.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::snoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:21
* @route '/api/internal/incidents/{incident}/snooze'
*/
snoozeIncidentForm.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snoozeIncident.url(args, options),
    method: 'post',
})

snoozeIncident.form = snoozeIncidentForm

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::unsnoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:30
* @route '/api/internal/incidents/{incident}/snooze'
*/
export const unsnoozeIncident = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unsnoozeIncident.url(args, options),
    method: 'delete',
})

unsnoozeIncident.definition = {
    methods: ["delete"],
    url: '/api/internal/incidents/{incident}/snooze',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::unsnoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:30
* @route '/api/internal/incidents/{incident}/snooze'
*/
unsnoozeIncident.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return unsnoozeIncident.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::unsnoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:30
* @route '/api/internal/incidents/{incident}/snooze'
*/
unsnoozeIncident.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unsnoozeIncident.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::unsnoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:30
* @route '/api/internal/incidents/{incident}/snooze'
*/
const unsnoozeIncidentForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeIncident.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\IncidentRemindersController::unsnoozeIncident
* @see app/Http/Controllers/Api/IncidentRemindersController.php:30
* @route '/api/internal/incidents/{incident}/snooze'
*/
unsnoozeIncidentForm.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnoozeIncident.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

unsnoozeIncident.form = unsnoozeIncidentForm

const internal = {
    getIncidentNote: Object.assign(getIncidentNote, getIncidentNote),
    saveIncidentNote: Object.assign(saveIncidentNote, saveIncidentNote),
    snoozeIncident: Object.assign(snoozeIncident, snoozeIncident),
    unsnoozeIncident: Object.assign(unsnoozeIncident, unsnoozeIncident),
}

export default internal