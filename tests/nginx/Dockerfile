# syntax=docker/dockerfile:1
# nginx with nginx-module-cache-purge built from the pinned upstream tag.
#
# The official image is built --with-compat, so a dynamic module compiled
# against the same nginx source tarball loads into the stock binary. This
# keeps the harness hermetic: no GetPageSpeed repository access is required.
ARG NGINX_IMAGE=nginx:stable-bookworm

FROM ${NGINX_IMAGE} AS builder
ARG NGX_CACHE_PURGE_REF=2.6.1
RUN apt-get update \
 && apt-get install -y --no-install-recommends build-essential ca-certificates curl git openssh-client libpcre2-dev libssl-dev zlib1g-dev \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /src
# ngx_cache_purge is a private repository: clone it over SSH through the
# BuildKit agent forward (`docker compose build --ssh default`, or the
# read-only deploy key that CI loads into ssh-agent). No credential is stored
# in any image layer.
RUN mkdir -p -m 0700 /root/.ssh && ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
RUN --mount=type=ssh \
    curl -fsSL "https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz" | tar xz \
 && git clone --depth 1 --branch "${NGX_CACHE_PURGE_REF}" git@github.com:GetPageSpeed/ngx_cache_purge.git
RUN cd "nginx-${NGINX_VERSION}" \
 && ./configure --with-compat --add-dynamic-module=/src/ngx_cache_purge \
 && make -j"$(nproc)" modules \
 && cp objs/ngx_http_cache_purge_module.so /ngx_http_cache_purge_module.so

FROM ${NGINX_IMAGE}
COPY --from=builder /ngx_http_cache_purge_module.so /etc/nginx/modules/ngx_http_cache_purge_module.so
RUN sed -i '1i load_module modules/ngx_http_cache_purge_module.so;' /etc/nginx/nginx.conf \
 && mkdir -p /var/cache/nginx/vhp-tags /var/cache/nginx/vhp-notags /var/cache/nginx/vhp-restricted \
 && nginx -t
