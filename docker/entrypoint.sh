#!/bin/sh

set -eu

php vendor/bin/phinx migrate -c phinx.php
php vendor/bin/phinx seed:run -c phinx.php -s InitialAdministratorSeeder

exec php -S 0.0.0.0:8080 -t public
