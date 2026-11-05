FROM --platform=$BUILDPLATFORM golang:1.26.10-alpine@sha256:9ce231b28998b32e116927c61aa5c406183061d4f3f2d61b292c88e7f6bdf7ca AS proxy-build
ARG TARGETOS
ARG TARGETARCH
WORKDIR /proxy
COPY --chmod=0644 docker/lighthouse/proxy/go.mod docker/lighthouse/proxy/go.sum ./
RUN go mod download
# PHP config and the embedded proxy policy use the same metadata endpoint list.
COPY --chmod=0644 config/outbound-metadata.json ./metadata-targets.json
COPY docker/lighthouse/proxy/ ./
RUN chmod -R u=rwX,go=rX /proxy \
    && go test -v ./... && CGO_ENABLED=0 GOOS=$TARGETOS GOARCH=$TARGETARCH go build -trimpath -ldflags="-s -w" -o /lighthouse-proxy .

FROM node:24-bookworm-slim@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6 AS lighthouse
# A daily cache key keeps Debian security updates fresh without pinning disappearing apt versions.
ARG APT_SNAPSHOT_DATE
WORKDIR /service
COPY --chmod=0644 docker/lighthouse/package.json docker/lighthouse/package-lock.json ./
# hadolint ignore=DL3008
RUN if [ -z "$APT_SNAPSHOT_DATE" ]; then echo 'APT_SNAPSHOT_DATE is required; set it to the current UTC date (YYYY-MM-DD)' >&2; exit 1; fi \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/* \
    && npm ci --omit=dev --ignore-scripts \
    && npm cache clean --force \
    && rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack /opt/yarn* \
    && rm -f /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack /usr/local/bin/yarn /usr/local/bin/yarnpkg
ARG HEPTAPOD_VERSION=unknown
ARG OCI_SOURCE=unknown
ARG OCI_REVISION=unknown
ARG OCI_CREATED
LABEL com.heptapod.component=lighthouse \
    org.opencontainers.image.title="Heptapod Lighthouse" \
    org.opencontainers.image.description="Isolated Lighthouse browser audit service" \
    org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.version=$HEPTAPOD_VERSION \
    org.opencontainers.image.licenses="AGPL-3.0-or-later" \
    org.opencontainers.image.created=$OCI_CREATED
ENV CHROME_PATH=/usr/bin/chromium CHROME_FLAGS="--headless --no-sandbox --disable-gpu --disable-dev-shm-usage" \
    PATH="/service/node_modules/.bin:${PATH}"
COPY --from=proxy-build --chmod=0755 /lighthouse-proxy /usr/local/bin/lighthouse-proxy
WORKDIR /service
# Keep diagnostics.mjs's ../../config import valid in the flattened runtime layout.
COPY --chmod=0644 config/credential-parameters.json /config/credential-parameters.json
RUN chmod 0755 /config
COPY --chown=node:node --chmod=0644 docker/lighthouse/server.mjs docker/lighthouse/audit.mjs docker/lighthouse/browser.mjs docker/lighthouse/diagnostics.mjs ./
USER 1000:1000
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD ["node", "-e", "fetch('http://localhost:3000/health').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"]
CMD ["node", "server.mjs"]
