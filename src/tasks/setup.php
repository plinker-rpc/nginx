<?php
/*
 +------------------------------------------------------------------------+
 | Plinker-RPC PHP                                                        |
 +------------------------------------------------------------------------+
 | Copyright (c)2017-2018 (https://github.com/plinker-rpc/core)           |
 +------------------------------------------------------------------------+
 | This source file is subject to MIT License                             |
 | that is bundled with this package in the file LICENSE.                 |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@cherone.co.uk so we can send you a copy immediately.        |
 +------------------------------------------------------------------------+
 | Authors: Lawrence Cherone <lawrence@cherone.co.uk>                     |
 +------------------------------------------------------------------------+
 */
 
#
## Install NGINX & PHP-FPM
#

/**
 * Create sites config folder
 */
if (!file_exists('/etc/nginx/proxied')) {
    mkdir('/etc/nginx/proxied', 0755, true);
}

if (!file_exists('/etc/nginx/proxied/conf')) {
    mkdir('/etc/nginx/proxied/conf', 0755, true);
}

if (!file_exists('/etc/nginx/proxied/includes')) {
    mkdir('/etc/nginx/proxied/includes', 0755, true);
}

if (!file_exists('/etc/nginx/proxied/servers')) {
    mkdir('/etc/nginx/proxied/servers', 0755, true);
}

/**
 * Create nginx error pages folder
 */
if (!file_exists('/usr/share/nginx/html/errors')) {
    mkdir('/usr/share/nginx/html/errors', 0755, true);
}

/**
 * Create lets encrypt folders
 */
if (!file_exists('/usr/share/nginx/html/letsencrypt')) {
    mkdir('/usr/share/nginx/html/letsencrypt', 0755, true);
}

if (!file_exists('/usr/share/nginx/html/.well-known')) {
    mkdir('/usr/share/nginx/html/.well-known', 0755, true);
}

/**
 * Start writing files
 */

#/etc/nginx/proxied/conf/default.conf
file_put_contents('/etc/nginx/proxied/conf/default.conf', '#
client_max_body_size 256M;

server {
    listen 127.0.0.1:80;
    server_name 127.0.0.1;
    location /nginx_status {
        stub_status on;
        allow 127.0.0.1;
        deny all;
    }
}

server {
    listen       80  default_server;
    server_name  _;
    return       404;
}

server {
    listen 88 default_server;
    listen [::]:88 default_server;
    root /var/www/html/public;
    #
    index index.php index.html;
    #
    server_name _;
    #
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    #
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:'.trim(`find /run/php/php*-fpm.sock`).';
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME     $fastcgi_script_name;
    }
    #
    location ~ /\.api {
      deny all;
      return 403;
    }
    #
    location ~ /\. {
      deny all;
      return 403;
    }
    #
    location ~ /\.ht {
        deny all;
    }
    #
    location ~ /.*\.db {
        deny all;
    }
}
');

#/etc/nginx/proxied/includes/proxy.conf
file_put_contents('/etc/nginx/proxied/includes/proxy.conf', '#
proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
proxy_redirect off;
proxy_buffering off;

proxy_http_version 1.1;

proxy_set_header        Host                $host;
proxy_set_header        X-Real-IP           $remote_addr;
proxy_set_header        X-Forwarded-For     $proxy_add_x_forwarded_for;
proxy_set_header        X-Forwarded-Proto   $scheme;
proxy_set_header        X-Forwarded-Port    $server_port;

proxy_set_header        Upgrade             $http_upgrade;
proxy_set_header        Connection          \'upgrade\';
proxy_cache_bypass      $http_upgrade;
');

#
#/etc/nginx/proxied/includes/ssl.conf
#
file_put_contents('/etc/nginx/proxied/includes/ssl.conf', '#
ssl on;
ssl_session_cache  builtin:1000  shared:SSL:10m;
ssl_protocols  TLSv1 TLSv1.1 TLSv1.2;
ssl_ciphers HIGH:!aNULL:!eNULL:!EXPORT:!CAMELLIA:!DES:!MD5:!PSK:!RC4;
ssl_prefer_server_ciphers on;
');

#
#/etc/nginx/nginx.conf
#
file_put_contents('/etc/nginx/nginx.conf', '#
user www-data;
worker_processes 5;
pid /run/nginx.pid;

events {
	worker_connections 500;
	# multi_accept on;
}

http {
	##
	# Basic Settings
	sendfile on;
	tcp_nopush on;
	tcp_nodelay on;
	keepalive_timeout 5;
	types_hash_max_size 2048;
	server_tokens off;
	server_names_hash_bucket_size  64;

	include /etc/nginx/mime.types;
	default_type application/octet-stream;

	##
	# Logging Settings
	access_log /var/log/nginx/access.log;
	error_log /var/log/nginx/error.log;

	##
	# Gzip Settings
	gzip on;
	gzip_disable "msie6";

	##
	# Virtual Host Configs
	include /etc/nginx/proxied/conf/*.conf;
	include /etc/nginx/proxied/servers/*/*.conf;
}');

#
# fix cgi.fix-pathinfo, upto php 7.10 - maybe change to finding out the dir
#
for ($i = 0;$i <= 10; $i++) {
    if (file_exists('/etc/php/7.'.$i.'/fpm/php.ini')) {
        file_put_contents(
            '/etc/php/7.'.$i.'/fpm/php.ini',
            str_replace(
                ';cgi.fix_pathinfo=1',
                'cgi.fix_pathinfo=0',
                file_get_contents('/etc/php/7.'.$i.'/fpm/php.ini')
            )
        );
    }
}

echo `nginx -s reload`;
