---
title: Application health
description: Read a Laravel or Statamic site's health endpoint and list its open security advisories per package.
sidebar:
    order: 3
---

The application health check reads a health endpoint in the Oh Dear format and shows each reported component. With the spatie security advisories check, it also lists the site's open security advisories.

## Report security advisories from a Laravel or Statamic site

Run these steps in the site's project. Statamic is a Laravel app, so the steps are the same.

1. Install the packages:

    ```bash
    composer require spatie/laravel-health spatie/security-advisories-health-check
    php artisan vendor:publish --tag="health-config"
    php artisan vendor:publish --tag="health-migrations"
    php artisan migrate
    ```

2. In `config/health.php`, set `'enabled' => true` under `oh_dear_endpoint`.
3. Add a long random secret to the site's `.env`:

    ```dotenv
    OH_DEAR_HEALTH_CHECK_SECRET=replace-with-a-long-random-secret
    ```

4. In `app/Providers/AppServiceProvider.php`, add the two imports and call `Health::checks()` in the `boot` method:

    ```php
    use Spatie\Health\Facades\Health;
    use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

    Health::checks([
        SecurityAdvisoriesCheck::new()->cacheResultsForMinutes(60),
    ]);
    ```

5. Deploy the site.

Result: `https://example.com/oh-dear-health-check-results` answers with JSON when the request sends the secret in the `oh-dear-health-check-secret` header. Without the header it answers `403`.

The cache keeps the site from asking Packagist for advisories on every check run. Keep the default check name `SecurityAdvisories`: Heptapod lists advisories only for a component with that name.

## Connect the endpoint

1. Open the site and select **Settings**.
2. Turn on **Application health**. The **Check settings** sheet opens.
3. Enter the endpoint URL in **URL**, for example `https://example.com/oh-dear-health-check-results`.
4. Enter the secret from the site's `.env` in **Shared secret**.
5. Select **Apply**, then **Save**.

Result: after the first run, the **Application health** tab lists the reported components.

## Security advisories

An open advisory makes the **Security advisories** component **Failed**. The check then follows the usual [failure alerts](../notifications.md).

- The component lists each advisory under its package: title, CVE when known, severity when known, affected versions, report date and a link to the advisory.
- **Sites affected** opens the **Sites** list filtered to that package or advisory.
- On the **Sites** list, the advisories filter set to **With advisories** shows every site with at least one open advisory.
- A run that reports no advisories clears the list and removes the site from the filter.
- A **Check error** or a skipped advisories result keeps the last reported advisories.

## Results

The worst component sets the check result.

| Result          | When                                                                                          |
| --------------- | --------------------------------------------------------------------------------------------- |
| **Failed**      | At least one component reports `failed` or `crashed`.                                         |
| **Warning**     | At least one component reports `warning`, and none failed.                                    |
| **Check error** | The endpoint is unreachable, redirects, returns an error status or invalid JSON, or is stale. |

- A `skipped` component is unverified and never counts as healthy.
- A redirect is a **Check error**: enter the final address as **URL**.

## Settings

| Setting             | API key      | Default | Values                                                       |
| ------------------- | ------------ | ------- | ------------------------------------------------------------ |
| **URL**             | `result_url` | none    | An `http` or `https` URL                                     |
| **Shared secret**   | `secret`     | none    | Sent in the `oh-dear-health-check-secret` header             |
| **Request headers** | `headers`    | none    | Extra headers as name and value pairs; values stay encrypted |
