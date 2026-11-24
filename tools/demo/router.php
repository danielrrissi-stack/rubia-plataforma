<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('403 Forbidden');
}

// Roteador do servidor embutido do PHP para a clinica ficticia: sem ele, enderecos com ponto
// (ex.: /pacientes/1/evolucao.json) viram "arquivo nao encontrado". Arquivos reais de public/ saem direto.
// Uso: APP_ENV=demo php -S 127.0.0.1:8001 -t public tools/demo/router.php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath(__DIR__ . '/../../public' . $path);
if ($file !== false && is_file($file) && str_starts_with($file, realpath(__DIR__ . '/../../public'))) {
    return false;
}
require __DIR__ . '/../../public/index.php';
