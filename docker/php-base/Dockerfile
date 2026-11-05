FROM dunglas/frankenphp:1.13.4-php8.5.12@sha256:ada77754f475352a9105b7961f89ab17ece97048514799532d2d27a1dea2fda7 AS runtime

COPY --chmod=0644 docker/php-extensions.txt /usr/local/etc/php/extensions.txt

# A daily cache key makes cached builds pick up Debian security updates.
ARG APT_SNAPSHOT_DATE
# hadolint ignore=DL3008
RUN if [ -z "$APT_SNAPSHOT_DATE" ]; then echo 'APT_SNAPSHOT_DATE is required; set it to the current UTC date (YYYY-MM-DD)' >&2; exit 1; fi \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends curl dnsutils postgresql-client procps supervisor tini unzip \
    && rm -rf /var/lib/apt/lists/* \
    && awk 'NF && $1 !~ /^#/ && $1 != "pcov" { print $1 }' /usr/local/etc/php/extensions.txt > /tmp/php-extensions \
    && xargs install-php-extensions < /tmp/php-extensions \
    && rm /tmp/php-extensions

# Check the full module surface, including modules inherited from FrankenPHP.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
RUN export LC_ALL=C \
    && awk 'NF && $1 !~ /^#/ && $1 != "pcov" { print $1 }' /usr/local/etc/php/extensions.txt | sort > /tmp/php-expected \
    && php -m > /tmp/php-modules \
    && awk '/^\[Zend Modules\]/ { exit } /^\[/ || !NF { next } { sub(/^Zend /, ""); print tolower($0) }' /tmp/php-modules | sort > /tmp/php-actual \
    && diff -u /tmp/php-expected /tmp/php-actual \
    && rm /tmp/php-expected /tmp/php-modules /tmp/php-actual
# SHELL is stored in the image config and inherited by every FROM of this base; restore the default for the app stages.
SHELL ["/bin/sh", "-c"]

ARG PHP_BASE_INPUTS
ARG OCI_SOURCE
ARG OCI_REVISION
ARG OCI_CREATED
LABEL com.heptapod.php-base-inputs=$PHP_BASE_INPUTS \
    org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.created=$OCI_CREATED

FROM runtime AS coverage
RUN install-php-extensions pcov
