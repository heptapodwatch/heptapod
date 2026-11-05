import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
const ViewController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ViewController.url(options),
    method: 'get',
})

ViewController.definition = {
    methods: ["get","head"],
    url: '/dev-login',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
ViewController.url = (options?: RouteQueryOptions) => {
    return ViewController.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
ViewController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ViewController.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
ViewController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ViewController.url(options),
    method: 'head',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
const ViewControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ViewController.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
ViewControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ViewController.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/dev-login'
*/
ViewControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ViewController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ViewController.form = ViewControllerForm

export default ViewController