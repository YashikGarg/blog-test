#!/bin/bash
# Runs once, on the first start with an empty volume. It sets up the least-privilege users (mysql-patterns skill).
set -euo pipefail

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASS}';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.* TO '${APP_DB_USER}'@'%';  -- no DELETE: soft delete only

CREATE USER IF NOT EXISTS '${MIGRATE_DB_USER}'@'%' IDENTIFIED BY '${MIGRATE_DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON \`${MYSQL_DATABASE}\`.* TO '${MIGRATE_DB_USER}'@'%';

DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'%';
FLUSH PRIVILEGES;
SQL
