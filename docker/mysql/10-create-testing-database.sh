#!/usr/bin/env bash
set -euo pipefail

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS personal_budget_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON personal_budget_testing.* TO '$MYSQL_USER'@'%';
EOSQL
