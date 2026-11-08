import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import messages from './messages'
import notifications1ce82a from './notifications'
/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/clients',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
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
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/clients/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::create
* @see app/Http/Controllers/ClientSettingsController.php:23
* @route '/clients/create'
*/
createForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

create.form = createForm

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::store
* @see app/Http/Controllers/ClientSettingsController.php:30
* @route '/clients'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/clients',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::store
* @see app/Http/Controllers/ClientSettingsController.php:30
* @route '/clients'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::store
* @see app/Http/Controllers/ClientSettingsController.php:30
* @route '/clients'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::store
* @see app/Http/Controllers/ClientSettingsController.php:30
* @route '/clients'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::store
* @see app/Http/Controllers/ClientSettingsController.php:30
* @route '/clients'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
export const show = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/clients/{client}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
show.url = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: args.client,
    }

    return show.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
show.get = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
show.head = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
const showForm = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
showForm.get = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
showForm.head = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
export const logo = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logo.url(args, options),
    method: 'get',
})

logo.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/logo/{hash}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
logo.url = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            hash: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
        hash: args.hash,
    }

    return logo.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{hash}', parsedArgs.hash.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
logo.get = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logo.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
logo.head = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: logo.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
const logoForm = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: logo.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
logoForm.get = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: logo.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
logoForm.head = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: logo.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

logo.form = logoForm

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
export const settings = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

settings.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
settings.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return settings.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
settings.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
settings.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: settings.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
const settingsForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
settingsForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::settings
* @see app/Http/Controllers/ClientSettingsController.php:43
* @route '/clients/{client}/settings'
*/
settingsForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: settings.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

settings.form = settingsForm

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::update
* @see app/Http/Controllers/ClientSettingsController.php:53
* @route '/clients/{client}'
*/
export const update = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/clients/{client}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::update
* @see app/Http/Controllers/ClientSettingsController.php:53
* @route '/clients/{client}'
*/
update.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::update
* @see app/Http/Controllers/ClientSettingsController.php:53
* @route '/clients/{client}'
*/
update.patch = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::update
* @see app/Http/Controllers/ClientSettingsController.php:53
* @route '/clients/{client}'
*/
const updateForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientSettingsController::update
* @see app/Http/Controllers/ClientSettingsController.php:53
* @route '/clients/{client}'
*/
updateForm.patch = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
export const restore = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restore.url(args, options),
    method: 'post',
})

restore.definition = {
    methods: ["post"],
    url: '/clients/{client}/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
restore.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return restore.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
restore.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restore.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
const restoreForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: restore.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
restoreForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: restore.url(args, options),
    method: 'post',
})

restore.form = restoreForm

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
export const archive = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: archive.url(args, options),
    method: 'post',
})

archive.definition = {
    methods: ["post"],
    url: '/clients/{client}/archive',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
archive.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return archive.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
archive.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: archive.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
const archiveForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: archive.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
archiveForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: archive.url(args, options),
    method: 'post',
})

archive.form = archiveForm

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
export const moveBlockers = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: moveBlockers.url(args, options),
    method: 'get',
})

moveBlockers.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/move-blockers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
moveBlockers.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return moveBlockers.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
moveBlockers.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: moveBlockers.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
moveBlockers.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: moveBlockers.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
const moveBlockersForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: moveBlockers.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
moveBlockersForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: moveBlockers.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
moveBlockersForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: moveBlockers.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

moveBlockers.form = moveBlockersForm

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
export const notifications = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: notifications.url(args, options),
    method: 'get',
})

notifications.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/notifications',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
notifications.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return notifications.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
notifications.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: notifications.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
notifications.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: notifications.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
const notificationsForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notifications.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
notificationsForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notifications.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::notifications
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:23
* @route '/clients/{client}/notifications'
*/
notificationsForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notifications.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

notifications.form = notificationsForm

const clients = {
    index: Object.assign(index, index),
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    logo: Object.assign(logo, logo),
    settings: Object.assign(settings, settings),
    update: Object.assign(update, update),
    restore: Object.assign(restore, restore),
    archive: Object.assign(archive, archive),
    moveBlockers: Object.assign(moveBlockers, moveBlockers),
    messages: Object.assign(messages, messages),
    notifications: Object.assign(notifications, notifications1ce82a),
}

export default clients