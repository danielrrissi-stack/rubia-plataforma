<?php
declare(strict_types=1);

// Clinica ficticia do manual (APP_ENV=demo). Copie para config/config.demo.php na maquina de desenvolvimento.
// O banco PRECISA ser o local rubia_demo: tools/demo/seed.php recusa qualquer outro.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3307, // instancia separada: start_demo_db (tools/env.sh)
        'name' => 'rubia_demo',
        'user' => 'root',
        'pass' => '',
    ],
    'base_url'     => 'http://127.0.0.1:8001',
    'storage_path' => __DIR__ . '/../storage/demo',
    'mail' => [
        'from'      => 'demo@localhost',
        'from_name' => 'Rubia Demo',
    ],
];
