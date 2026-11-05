import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/incidents',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentsIndexController::__invoke
* @see app/Http/Controllers/IncidentsIndexController.php:22
* @route '/incidents'
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
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
export const exportMethod = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})

exportMethod.definition = {
    methods: ["get","head"],
    url: '/incidents/export',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
exportMethod.url = (options?: RouteQueryOptions) => {
    return exportMethod.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
exportMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
exportMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: exportMethod.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
const exportMethodForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: exportMethod.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
exportMethodForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: exportMethod.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentExportController::__invoke
* @see app/Http/Controllers/IncidentExportController.php:16
* @route '/incidents/export'
*/
exportMethodForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: exportMethod.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

exportMethod.form = exportMethodForm

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
export const alertPreview = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: alertPreview.url(args, options),
    method: 'get',
})

alertPreview.definition = {
    methods: ["get","head"],
    url: '/incidents/{incident}/alerts/{event}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
alertPreview.url = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            incident: args[0],
            event: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        incident: args.incident,
        event: args.event,
    }

    return alertPreview.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace('{event}', parsedArgs.event.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
alertPreview.get = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: alertPreview.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
alertPreview.head = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: alertPreview.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
const alertPreviewForm = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: alertPreview.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
alertPreviewForm.get = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: alertPreview.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentAlertPreviewController::__invoke
* @see app/Http/Controllers/IncidentAlertPreviewController.php:20
* @route '/incidents/{incident}/alerts/{event}'
*/
alertPreviewForm.head = (args: { incident: string | number, event: string | number } | [incident: string | number, event: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: alertPreview.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

alertPreview.form = alertPreviewForm

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
export const show = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/incidents/{incident}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
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
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
show.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
show.head = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
const showForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
*/
showForm.get = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\IncidentDetailController::__invoke
* @see app/Http/Controllers/IncidentDetailController.php:38
* @route '/incidents/{incident}'
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
* @see \Heptapod\Http\Controllers\IncidentActionsController::note
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
export const note = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: note.url(args, options),
    method: 'put',
})

note.definition = {
    methods: ["put"],
    url: '/incidents/{incident}/note',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::note
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
note.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return note.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::note
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
note.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: note.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::note
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
const noteForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: note.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::note
* @see app/Http/Controllers/IncidentActionsController.php:17
* @route '/incidents/{incident}/note'
*/
noteForm.put = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: note.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

note.form = noteForm

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::snooze
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
export const snooze = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snooze.url(args, options),
    method: 'post',
})

snooze.definition = {
    methods: ["post"],
    url: '/incidents/{incident}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::snooze
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
snooze.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return snooze.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::snooze
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
snooze.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::snooze
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
const snoozeForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::snooze
* @see app/Http/Controllers/IncidentActionsController.php:31
* @route '/incidents/{incident}/snooze'
*/
snoozeForm.post = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snooze.url(args, options),
    method: 'post',
})

snooze.form = snoozeForm

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::unsnooze
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
export const unsnooze = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unsnooze.url(args, options),
    method: 'delete',
})

unsnooze.definition = {
    methods: ["delete"],
    url: '/incidents/{incident}/snooze',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::unsnooze
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
unsnooze.url = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return unsnooze.definition.url
            .replace('{incident}', parsedArgs.incident.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::unsnooze
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
unsnooze.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: unsnooze.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::unsnooze
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
const unsnoozeForm = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnooze.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\IncidentActionsController::unsnooze
* @see app/Http/Controllers/IncidentActionsController.php:41
* @route '/incidents/{incident}/snooze'
*/
unsnoozeForm.delete = (args: { incident: string | number } | [incident: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnooze.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

unsnooze.form = unsnoozeForm

const incidents = {
    index: Object.assign(index, index),
    export: Object.assign(exportMethod, exportMethod),
    alertPreview: Object.assign(alertPreview, alertPreview),
    show: Object.assign(show, show),
    note: Object.assign(note, note),
    snooze: Object.assign(snooze, snooze),
    unsnooze: Object.assign(unsnooze, unsnooze),
}

export default incidents