vcl 4.1;

import std;

backend default {
    .host = "wordpress";
    .port = "80";
    .connect_timeout = 5s;
    .first_byte_timeout = 60s;
    .between_bytes_timeout = 60s;
}

acl purge {
    "localhost";
    "127.0.0.1";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_recv {
    if (req.method == "PURGE") {
        if (!(client.ip ~ purge)) {
            return (synth(405, "PURGE not allowed"));
        }
        /* Use BAN to invalidate cache */
        if (req.http.X-Purge-Method == "regex") {
            ban("obj.http.X-Url ~ .");
            return (synth(200, "Banned all URLs"));
        }
        ban("obj.http.X-Url == " + req.url);
        return (synth(200, "Banned specific URL"));
    }

    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    /* Do not cache admin, login, previews */
    if (req.url ~ "^/wp-(login|admin)" || req.url ~ "preview=true") {
        return (pass);
    }

    if (req.http.Authorization) {
        return (pass);
    }

    if (req.http.Cookie) {
        if (req.http.Cookie ~ "wordpress_logged_in|wp-postpass|wordpress_sec|comment_author|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session") {
            return (pass);
        }
        /* Strip other cookies */
        unset req.http.Cookie;
    }

    return (hash);
}

sub vcl_hash {
    hash_data(req.url);
    if (req.http.host) {
        hash_data(req.http.host);
    } else {
        hash_data(server.ip);
    }
}

sub vcl_backend_response {
    if (bereq.url ~ "^/wp-(login|admin)") {
        set beresp.uncacheable = true;
        return (deliver);
    }

    if (beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        return (deliver);
    }

    if (beresp.http.Cache-Control ~ "no-cache|no-store") {
        set beresp.uncacheable = true;
        return (deliver);
    }

    if (beresp.ttl <= 0s) {
        set beresp.ttl = 120s;
    }
    set beresp.grace = 30s;

    /* Tag objects so we can BAN by host+url */
    set beresp.http.X-Url = bereq.url;
    if (bereq.http.host) {
        set beresp.http.X-Host = bereq.http.host;
    } else {
        set beresp.http.X-Host = server.ip;
    }
}

sub vcl_deliver {
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
    set resp.http.X-Cache-Hits = obj.hits;
}



