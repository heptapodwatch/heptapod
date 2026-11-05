import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
export const index = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/client-messages',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
index.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { client: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
    }

    return index.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
index.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
index.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
const indexForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
indexForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::index
* @see app/Http/Controllers/ClientMessagesController.php:25
* @route '/clients/{client}/client-messages'
*/
indexForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
export const show = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/client-messages/{message}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
show.url = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
        message: args.message,
    }

    return show.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
show.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
show.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
const showForm = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
showForm.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::show
* @see app/Http/Controllers/ClientMessagesController.php:39
* @route '/clients/{client}/client-messages/{message}'
*/
showForm.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
export const attachment = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: attachment.url(args, options),
    method: 'get',
})

attachment.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/client-messages/{message}/attachment',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
attachment.url = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
        message: args.message,
    }

    return attachment.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
attachment.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: attachment.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
attachment.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: attachment.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
const attachmentForm = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: attachment.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
attachmentForm.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: attachment.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
attachmentForm.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: attachment.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

attachment.form = attachmentForm

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
export const preview = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

preview.definition = {
    methods: ["post"],
    url: '/clients/{client}/client-messages/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
preview.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { client: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
    }

    return preview.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
preview.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
const previewForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preview.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
previewForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preview.url(args, options),
    method: 'post',
})

preview.form = previewForm

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::store
* @see app/Http/Controllers/ClientMessagesController.php:62
* @route '/clients/{client}/client-messages'
*/
export const store = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/clients/{client}/client-messages',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::store
* @see app/Http/Controllers/ClientMessagesController.php:62
* @route '/clients/{client}/client-messages'
*/
store.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { client: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
    }

    return store.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::store
* @see app/Http/Controllers/ClientMessagesController.php:62
* @route '/clients/{client}/client-messages'
*/
store.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::store
* @see app/Http/Controllers/ClientMessagesController.php:62
* @route '/clients/{client}/client-messages'
*/
const storeForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagesController::store
* @see app/Http/Controllers/ClientMessagesController.php:62
* @route '/clients/{client}/client-messages'
*/
storeForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

const messages = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    attachment: Object.assign(attachment, attachment),
    preview: Object.assign(preview, preview),
    store: Object.assign(store, store),
}

export default messages