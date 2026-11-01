<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require __DIR__ . '/../app/bootstrap.php';
