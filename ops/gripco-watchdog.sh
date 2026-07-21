#!/bin/sh
set -eu

project_dir=/var/www/gripco-wp
health_url=http://127.0.0.1:8080/wp-login.php

if curl --fail --silent --show-error --max-time 15 --output /dev/null --header 'Host: gripcosaudia.com' "$health_url"; then
    exit 0
fi

logger -t gripco-watchdog 'WordPress upstream failed; recreating the stateless WordPress container'
cd "$project_dir"
/usr/bin/docker compose up -d --force-recreate wordpress

attempt=0
while [ "$attempt" -lt 6 ]; do
    if curl --fail --silent --show-error --max-time 10 --output /dev/null --header 'Host: gripcosaudia.com' "$health_url"; then
        logger -t gripco-watchdog 'WordPress upstream recovered'
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 5
done

logger -t gripco-watchdog 'WordPress upstream did not recover'
exit 1
