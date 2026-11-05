import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
import health from './health'
import attempts from './attempts'
import tasks from './tasks'
/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
export const show = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
show.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return show.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
show.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
show.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
const showForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
showForm.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\CheckTabController::__invoke
* @see app/Http/Controllers/CheckTabController.php:15
* @route '/sites/{site}/checks/{check}'
*/
showForm.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
export const findings = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: findings.url(args, options),
    method: 'get',
})

findings.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/checks/{check}/findings.csv',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
findings.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return findings.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
findings.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: findings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
findings.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: findings.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
const findingsForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
findingsForm.get = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\FindingsExportController::__invoke
* @see app/Http/Controllers/FindingsExportController.php:25
* @route '/sites/{site}/checks/{check}/findings.csv'
*/
findingsForm.head = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: findings.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

findings.form = findingsForm

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
export const update = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/sites/{site}/checks/{check}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
update.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return update.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
update.patch = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
const updateForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
updateForm.patch = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
export const enable = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

enable.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/enable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
enable.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return enable.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
enable.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: enable.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
const enableForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enable.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\EnableSiteCheckController::__invoke
* @see app/Http/Controllers/EnableSiteCheckController.php:17
* @route '/sites/{site}/checks/{check}/enable'
*/
enableForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enable.url(args, options),
    method: 'post',
})

enable.form = enableForm

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
export const run = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: run.url(args, options),
    method: 'post',
})

run.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/run',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
run.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return run.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
run.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: run.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
const runForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: run.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RequestCheckRunController::__invoke
* @see app/Http/Controllers/RequestCheckRunController.php:20
* @route '/sites/{site}/checks/{check}/run'
*/
runForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: run.url(args, options),
    method: 'post',
})

run.form = runForm

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
export const acceptDns = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: acceptDns.url(args, options),
    method: 'post',
})

acceptDns.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/accept-dns',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
acceptDns.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return acceptDns.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
acceptDns.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: acceptDns.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
const acceptDnsForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: acceptDns.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
acceptDnsForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: acceptDns.url(args, options),
    method: 'post',
})

acceptDns.form = acceptDnsForm

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
export const cancel = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
cancel.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return cancel.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
cancel.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
const cancelForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\CancelCheckRunController::__invoke
* @see app/Http/Controllers/CancelCheckRunController.php:17
* @route '/sites/{site}/checks/{check}/cancel'
*/
cancelForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, options),
    method: 'post',
})

cancel.form = cancelForm

const checks = {
    show: Object.assign(show, show),
    findings: Object.assign(findings, findings),
    update: Object.assign(update, update),
    enable: Object.assign(enable, enable),
    health: Object.assign(health, health),
    run: Object.assign(run, run),
    attempts: Object.assign(attempts, attempts),
    acceptDns: Object.assign(acceptDns, acceptDns),
    tasks: Object.assign(tasks, tasks),
    cancel: Object.assign(cancel, cancel),
}

export default checks