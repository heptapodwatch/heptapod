import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
export const dismiss = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: dismiss.url(options),
    method: 'delete',
})

dismiss.definition = {
    methods: ["delete"],
    url: '/settings/notifications/team/migration-report',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
dismiss.url = (options?: RouteQueryOptions) => {
    return dismiss.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
dismiss.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: dismiss.url(options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
const dismissForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dismiss.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
dismissForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dismiss.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

dismiss.form = dismissForm

const migrationReport = {
    dismiss: Object.assign(dismiss, dismiss),
}

export default migrationReport