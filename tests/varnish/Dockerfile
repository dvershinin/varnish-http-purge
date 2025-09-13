FROM varnish:7.4

# Add curl for healthchecks
RUN apt-get update && apt-get install -y --no-install-recommends curl \
  && rm -rf /var/lib/apt/lists/*

COPY default.vcl /etc/varnish/default.vcl

EXPOSE 80


