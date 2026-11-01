<?php
declare(strict_types=1);

// Copie para config/config.php (produção/dev) ou config/config.test.php (testes).
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'rubia',
        'user' => 'rubia',
        'pass' => '',
    ],
    'base_url'     => 'https://exemplo.com.br',
    'storage_path' => __DIR__ . '/../storage',
    'mail' => [
        'from'      => 'nao-responda@exemplo.com.br',
        'from_name' => 'Rubia Nutrição',
    ],
    'session' => [
        'staff_idle_minutes'  => 30,
        'family_idle_minutes' => 120,
    ],
];
