# Public Suffix List

`public_suffix_list.dat` is the unmodified Mozilla Public Suffix List.

- Source: https://publicsuffix.org/list/public_suffix_list.dat
- Download date: 2026-10-01
- License: Mozilla Public License 2.0 (MPL-2.0), https://mozilla.org/MPL/2.0/
- Attribution: Mozilla Public Suffix List contributors; copyright and source notices remain in the data file.

Maintainers refresh this file in a source checkout with `docker compose exec app php artisan heptapod:psl:refresh` before building a release image. Production refresh is disabled. See `docs/deploy/operations.md`.
