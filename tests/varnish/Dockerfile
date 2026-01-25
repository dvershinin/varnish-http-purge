FROM varnish:7.4

COPY default.vcl /etc/varnish/default.vcl

# Listen on Varnish's default port
EXPOSE 6081

CMD ["varnishd", "-F", "-a", ":6081", "-f", "/etc/varnish/default.vcl", "-s", "malloc,256m"]


