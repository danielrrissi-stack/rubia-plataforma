# Rubia - acompanhamento nutricional

PHP 8.3 + MySQL/MariaDB, hospedagem cPanel.

## Ambiente local (Windows)
    source tools/env.sh        # php e mariadb no PATH
    start_mariadb              # se o servidor não estiver rodando
    cp config/config.example.php config/config.php   # e config.test.php
    php tools/phpunit.phar --testsuite Unit
