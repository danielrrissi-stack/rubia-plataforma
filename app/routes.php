<?php
declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Auth\AuthController;
use App\Auth\TwoFactorController;
use App\Backup\BackupController;
use App\Core\Request;
use App\Install\InstallController;
use App\Core\Response;
use App\Core\View;
use App\Users\UserController;

require_once __DIR__ . '/middleware.php';

$router->get('/instalar', [InstallController::class, 'show']);
$router->post('/instalar', [InstallController::class, 'install']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/senha/esqueci', [AuthController::class, 'showForgot']);
$router->post('/senha/esqueci', [AuthController::class, 'forgot']);
$router->get('/senha/definir/{token}', [AuthController::class, 'showReset']);
$router->post('/senha/definir/{token}', [AuthController::class, 'reset']);

$router->get('/2fa', [TwoFactorController::class, 'showVerify']);
$router->post('/2fa', [TwoFactorController::class, 'verify']);
$router->get('/2fa/configurar', [TwoFactorController::class, 'showSetup'], ['auth', 'twofactor']);
$router->post('/2fa/configurar', [TwoFactorController::class, 'setup'], ['auth', 'twofactor']);

// Provisoria: sera substituida pelo Dashboard (Task 11).
$router->get('/', static function (Request $r): Response {
    $user = $r->attributes['user'];
    $family = in_array($user['role'], ['paciente', 'responsavel', 'crianca'], true);
    $layout = $family ? 'Layout/views/layout_portal.php' : 'Layout/views/layout.php';
    return Response::html(View::render(
        'Auth/views/home.php',
        ['title' => 'Início', 'userName' => $user['name']],
        $layout
    ));
}, ['auth', 'twofactor']);

$staffMw = ['auth', 'twofactor'];
$router->get('/equipe', [UserController::class, 'index'], $staffMw);
$router->get('/equipe/novo', [UserController::class, 'create'], $staffMw);
$router->post('/equipe/novo', [UserController::class, 'store'], $staffMw);
$router->get('/equipe/{id:\d+}/editar', [UserController::class, 'edit'], $staffMw);
$router->post('/equipe/{id:\d+}/editar', [UserController::class, 'update'], $staffMw);
$router->post('/equipe/{id:\d+}/desativar', [UserController::class, 'deactivate'], $staffMw);
$router->post('/equipe/{id:\d+}/reativar', [UserController::class, 'reactivate'], $staffMw);
$router->post('/equipe/{id:\d+}/convite', [UserController::class, 'resendInvite'], $staffMw);

$router->get('/admin/backups', [BackupController::class, 'index'], $staffMw);
$router->post('/admin/backups/gerar', [BackupController::class, 'generate'], $staffMw);
$router->get('/admin/backups/chave-privada', [BackupController::class, 'privateKey'], $staffMw);
$router->get('/admin/backups/{nome}', [BackupController::class, 'download'], $staffMw);
