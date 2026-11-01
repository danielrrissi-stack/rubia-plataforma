<?php
declare(strict_types=1);

$APP_ROOT = dirname(__DIR__); // AJUSTE NO DEPLOY

require $APP_ROOT . '/app/bootstrap.php';

\App\Core\App::run(\App\Core\Request::fromGlobals())->send();
