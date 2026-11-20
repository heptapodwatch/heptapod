import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see routes/web.php:70
* @route '/dev/components'
*/
export const components = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: components.url(options),
    method: 'get',
})

components.definition = {
    methods: ["get","head"],
    url: '/dev/components',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
components.url = (options?: RouteQueryOptions) => {
    return components.definition.url + queryParams(options)
}

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
components.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: components.url(options),
    method: 'get',
})

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
components.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: components.url(options),
    method: 'head',
})

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
const componentsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: components.url(options),
    method: 'get',
})

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
componentsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: components.url(options),
    method: 'get',
})

/**
* @see routes/web.php:70
* @route '/dev/components'
*/
componentsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: components.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

components.form = componentsForm

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
export const errors = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: errors.url(options),
    method: 'get',
})

errors.definition = {
    methods: ["get","head"],
    url: '/dev/components/errors',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
errors.url = (options?: RouteQueryOptions) => {
    return errors.definition.url + queryParams(options)
}

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
errors.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: errors.url(options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
errors.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: errors.url(options),
    method: 'head',
})

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
const errorsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: errors.url(options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
errorsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: errors.url(options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/components/errors'
*/
errorsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: errors.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

errors.form = errorsForm

const dev = {
    components: Object.assign(components, components),
    errors: Object.assign(errors, errors),
}

export default dev