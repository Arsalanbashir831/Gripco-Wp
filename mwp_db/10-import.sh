# The Orion dump has utf8mb4 tables but no client charset declaration.
# docker_process_sql is provided by the official MariaDB entrypoint.
docker_process_sql --database="$MARIADB_DATABASE" --default-character-set=utf8mb4 < /dump/wordpress.sql
