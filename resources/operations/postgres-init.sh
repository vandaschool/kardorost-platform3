#!/bin/sh
set -eu
case "$APP_DB_USER" in postgres|template0|template1|''|*[!a-zA-Z0-9_]*) echo 'Invalid application database role.' >&2; exit 1;; esac
case "$APP_DB_NAME" in postgres|template0|template1|''|*[!a-zA-Z0-9_]*) echo 'Invalid application database name.' >&2; exit 1;; esac
[ -n "$APP_DB_PASSWORD" ] || { echo 'Application database password is required.' >&2; exit 1; }
[ "$APP_DB_PASSWORD" != "$POSTGRES_PASSWORD" ] || { echo 'Administrator and application database passwords must differ.' >&2; exit 1; }
psql --username "$POSTGRES_USER" --dbname postgres --set ON_ERROR_STOP=1 <<'SQL'
\getenv app_user APP_DB_USER
\getenv app_database APP_DB_NAME
\getenv app_password APP_DB_PASSWORD
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L', :'app_user', :'app_password') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'app_database', :'app_user') \gexec
SQL
