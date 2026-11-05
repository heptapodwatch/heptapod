import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RotateCronPingUrlController::__invoke
* @see app/Http/Controllers/RotateCronPingUrlController.php:19
* @route '/sites/{site}/checks/{check}/tasks/{task}/rotate-url'
*/
export const rotateUrl = (args: { site: string | number, check: string | number, task: string | number } | [site: string | number, check: string | number, task: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateUrl.url(args, options),
    method: 'post',
})

rotateUrl.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/tasks/{task}/rotate-url',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RotateCronPingUrlController::__invoke
* @see app/Http/Controllers/RotateCronPingUrlController.php:19
* @route '/sites/{site}/checks/{check}/tasks/{task}/rotate-url'
*/
rotateUrl.url = (args: { site: string | number, check: string | number, task: string | number } | [site: string | number, check: string | number, task: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
            task: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
        task: args.task,
    }

    return rotateUrl.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RotateCronPingUrlController::__invoke
* @see app/Http/Controllers/RotateCronPingUrlController.php:19
* @route '/sites/{site}/checks/{check}/tasks/{task}/rotate-url'
*/
rotateUrl.post = (args: { site: string | number, check: string | number, task: string | number } | [site: string | number, check: string | number, task: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateUrl.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RotateCronPingUrlController::__invoke
* @see app/Http/Controllers/RotateCronPingUrlController.php:19
* @route '/sites/{site}/checks/{check}/tasks/{task}/rotate-url'
*/
const rotateUrlForm = (args: { site: string | number, check: string | number, task: string | number } | [site: string | number, check: string | number, task: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateUrl.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RotateCronPingUrlController::__invoke
* @see app/Http/Controllers/RotateCronPingUrlController.php:19
* @route '/sites/{site}/checks/{check}/tasks/{task}/rotate-url'
*/
rotateUrlForm.post = (args: { site: string | number, check: string | number, task: string | number } | [site: string | number, check: string | number, task: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateUrl.url(args, options),
    method: 'post',
})

rotateUrl.form = rotateUrlForm

const tasks = {
    rotateUrl: Object.assign(rotateUrl, rotateUrl),
}

export default tasks