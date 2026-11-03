FROM caddy:2-alpine

COPY public /var/www/html/public
COPY deploy/staging/Caddyfile /etc/caddy/Caddyfile
