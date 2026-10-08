#!/bin/sh

set -eu

php vendor/bin/phinx migrate -c phinx.php
php vendor/bin/phinx seed:run -c phinx.php -s InitialAdministratorSeeder

export PHP_CLI_SERVER_WORKERS=4

exec php -S 0.0.0.0:8080 -t public