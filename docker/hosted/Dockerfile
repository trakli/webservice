# Trakli hosted image: the public base plus bundled private plugins,
# enabled and cached so paid features are available out of the box.
#
# The build context is prepared by the hosted-image workflow on the runner
# (private plugins cloned into plugins/, their dependencies installed into
# vendor/, and the plugin cache regenerated) before this image is built.
ARG BASE_IMAGE=ghcr.io/trakli/webservice:latest

FROM ${BASE_IMAGE}

LABEL org.opencontainers.image.source=https://github.com/trakli/webservice
LABEL org.opencontainers.image.description="Trakli hosted API webservice"
LABEL org.opencontainers.image.vendor="WhileSmart LLC"

USER root

COPY --chown=www-data:www-data plugins/ /var/www/html/plugins/
COPY --chown=www-data:www-data vendor/ /var/www/html/vendor/
COPY --chown=www-data:www-data bootstrap/cache/ /var/www/html/bootstrap/cache/
COPY --chown=www-data:www-data composer.json composer.lock /var/www/html/

USER www-data
