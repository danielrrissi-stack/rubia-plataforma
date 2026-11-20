<?php
declare(strict_types=1);

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/DbTestCase.php';
require __DIR__ . '/TestSchema.php';

// Recria o schema de rubia_test uma vez por execucao (derruba tudo, migra e carrega as
// dados de referencia de database/seeds: OMS e PA). Feito antes de qualquer transacao de teste: os dados nao sao revertidos.
Tests\TestSchema::rebuild(new App\Core\Database((array) App\Core\Config::get('db')));
