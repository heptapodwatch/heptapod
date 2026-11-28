import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
const DismissNotificationMigrationReportController = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: DismissNotificationMigrationReportController.url(options),
    method: 'delete',
})

DismissNotificationMigrationReportController.definition = {
    methods: ["delete"],
    url: '/settings/notifications/team/migration-report',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
DismissNotificationMigrationReportController.url = (options?: RouteQueryOptions) => {
    return DismissNotificationMigrationReportController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
DismissNotificationMigrationReportController.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: DismissNotificationMigrationReportController.url(options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\DismissNotificationMigrationReportController::__invoke
* @see app/Http/Controllers/Notifications/DismissNotificationMigrationReportController.php:16
* @route '/settings/notifications/team/migration-report'
*/
const DismissNotificationMigrationReportControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DismissNotificationMigrationReportController.url({
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
DismissNotificationMigrationReportControllerForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DismissNotificationMigrationReportController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

DismissNotificationMigrationReportController.form = DismissNotificationMigrationReportControllerForm

export default DismissNotificationMigrationReportController