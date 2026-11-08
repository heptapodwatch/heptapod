import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import maintenanceA92dc2 from './maintenance'
import icon14263b from './icon'
import checks from './checks'
/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/sites',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SitesIndexController::__invoke
* @see app/Http/Controllers/SitesIndexController.php:28
* @route '/sites'
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
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/sites/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
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
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
export const probe = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: probe.url(options),
    method: 'post',
})

probe.definition = {
    methods: ["post"],
    url: '/sites/probe',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
probe.url = (options?: RouteQueryOptions) => {
    return probe.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
probe.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: probe.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
const probeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: probe.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteProbeController::__invoke
* @see app/Http/Controllers/SiteProbeController.php:19
* @route '/sites/probe'
*/
probeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: probe.url(options),
    method: 'post',
})

probe.form = probeForm

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/sites',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
export const show = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/sites/{site}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
show.url = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
    }

    return show.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
show.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
show.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
const showForm = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
showForm.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteOverviewController::__invoke
* @see app/Http/Controllers/SiteOverviewController.php:15
* @route '/sites/{site}'
*/
showForm.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
export const maintenance = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: maintenance.url(args, options),
    method: 'get',
})

maintenance.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/maintenance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
maintenance.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return maintenance.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
maintenance.get = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: maintenance.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
maintenance.head = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: maintenance.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
const maintenanceForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: maintenance.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
maintenanceForm.get = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: maintenance.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\MaintenanceController::maintenance
* @see app/Http/Controllers/MaintenanceController.php:90
* @route '/sites/{site}/maintenance'
*/
maintenanceForm.head = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: maintenance.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

maintenance.form = maintenanceForm

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
export const icon = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: icon.url(args, options),
    method: 'get',
})

icon.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/icon/{hash}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
icon.url = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            hash: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
        hash: args.hash,
    }

    return icon.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{hash}', parsedArgs.hash.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
icon.get = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: icon.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
icon.head = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: icon.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
const iconForm = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: icon.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
iconForm.get = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: icon.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::icon
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
iconForm.head = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: icon.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

icon.form = iconForm

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
export const settings = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

settings.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
settings.url = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
    }

    return settings.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
settings.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
settings.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: settings.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
const settingsForm = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
settingsForm.get = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: settings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::settings
* @see app/Http/Controllers/SiteSettingsController.php:25
* @route '/sites/{site}/settings'
*/
settingsForm.head = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\SiteSettingsController::update
* @see app/Http/Controllers/SiteSettingsController.php:33
* @route '/sites/{site}'
*/
export const update = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/sites/{site}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::update
* @see app/Http/Controllers/SiteSettingsController.php:33
* @route '/sites/{site}'
*/
update.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return update.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::update
* @see app/Http/Controllers/SiteSettingsController.php:33
* @route '/sites/{site}'
*/
update.patch = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::update
* @see app/Http/Controllers/SiteSettingsController.php:33
* @route '/sites/{site}'
*/
const updateForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::update
* @see app/Http/Controllers/SiteSettingsController.php:33
* @route '/sites/{site}'
*/
updateForm.patch = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\SiteSettingsController::destroy
* @see app/Http/Controllers/SiteSettingsController.php:51
* @route '/sites/{site}'
*/
export const destroy = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/sites/{site}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::destroy
* @see app/Http/Controllers/SiteSettingsController.php:51
* @route '/sites/{site}'
*/
destroy.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return destroy.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::destroy
* @see app/Http/Controllers/SiteSettingsController.php:51
* @route '/sites/{site}'
*/
destroy.delete = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::destroy
* @see app/Http/Controllers/SiteSettingsController.php:51
* @route '/sites/{site}'
*/
const destroyForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteSettingsController::destroy
* @see app/Http/Controllers/SiteSettingsController.php:51
* @route '/sites/{site}'
*/
destroyForm.delete = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\SiteMonitoringController::pause
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
export const pause = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: pause.url(args, options),
    method: 'post',
})

pause.definition = {
    methods: ["post"],
    url: '/sites/{site}/pause',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::pause
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
pause.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return pause.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::pause
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
pause.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: pause.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::pause
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
const pauseForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: pause.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::pause
* @see app/Http/Controllers/SiteMonitoringController.php:19
* @route '/sites/{site}/pause'
*/
pauseForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: pause.url(args, options),
    method: 'post',
})

pause.form = pauseForm

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::resume
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
export const resume = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resume.url(args, options),
    method: 'post',
})

resume.definition = {
    methods: ["post"],
    url: '/sites/{site}/resume',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::resume
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
resume.url = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { site: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return resume.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::resume
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
resume.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resume.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::resume
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
const resumeForm = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: resume.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteMonitoringController::resume
* @see app/Http/Controllers/SiteMonitoringController.php:35
* @route '/sites/{site}/resume'
*/
resumeForm.post = (args: { site: number | { id: number } } | [site: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: resume.url(args, options),
    method: 'post',
})

resume.form = resumeForm

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
export const runAll = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: runAll.url(args, options),
    method: 'post',
})

runAll.definition = {
    methods: ["post"],
    url: '/sites/{site}/run-all',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
runAll.url = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { site: args }
    }

    if (Array.isArray(args)) {
        args = {
            site: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
    }

    return runAll.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
runAll.post = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: runAll.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
const runAllForm = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: runAll.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestSiteChecksController::__invoke
* @see app/Http/Controllers/RequestSiteChecksController.php:17
* @route '/sites/{site}/run-all'
*/
runAllForm.post = (args: { site: string | number } | [site: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: runAll.url(args, options),
    method: 'post',
})

runAll.form = runAllForm

const sites = {
    index: Object.assign(index, index),
    create: Object.assign(create, create),
    probe: Object.assign(probe, probe),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    maintenance: Object.assign(maintenance, maintenanceA92dc2),
    icon: Object.assign(icon, icon14263b),
    settings: Object.assign(settings, settings),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    pause: Object.assign(pause, pause),
    resume: Object.assign(resume, resume),
    checks: Object.assign(checks, checks),
    runAll: Object.assign(runAll, runAll),
}

export default sites