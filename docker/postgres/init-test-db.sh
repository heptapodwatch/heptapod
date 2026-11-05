#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set=test_database="$POSTGRES_TEST_DB" <<'SQL'
CREATE DATABASE :"test_database";
SQL
