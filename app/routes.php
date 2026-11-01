<?php
declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Auth\AuthController;
use App\Auth\TwoFactorController;
use App\Backup\BackupController;
use App\Dashboard\DashboardController;
use App\Energy\EnergyController;
use App\Growth\GrowthController;
use App\Patients\AccessGrantController;
use App\Patients\GuardianController;
use App\Patients\PatientController;
use App\Install\InstallController;
use App\Records\RecordController;
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

$router->get('/', [DashboardController::class, 'index'], ['auth', 'twofactor', 'consent']);

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

$authMw = ['auth', 'twofactor', 'consent'];
$router->get('/pacientes', [PatientController::class, 'index'], $authMw);
$router->get('/pacientes/novo', [PatientController::class, 'create'], $authMw);
$router->post('/pacientes/novo', [PatientController::class, 'store'], $authMw);
$router->get('/pacientes/{id:\d+}', [PatientController::class, 'show'], $authMw);
$router->get('/pacientes/{id:\d+}/editar', [PatientController::class, 'edit'], $authMw);
$router->post('/pacientes/{id:\d+}/editar', [PatientController::class, 'update'], $authMw);
$router->post('/pacientes/{id:\d+}/acesso', [PatientController::class, 'grantAccess'], $authMw);
$router->get('/pacientes/{id:\d+}/responsaveis', [GuardianController::class, 'index'], $authMw);
$router->post('/pacientes/{id:\d+}/responsaveis', [GuardianController::class, 'add'], $authMw);
$router->post('/responsaveis/{linkId:\d+}/remover', [GuardianController::class, 'remove'], $authMw);
$router->get('/consentimento', [PatientController::class, 'showConsent'], ['auth', 'twofactor', 'consent']);
$router->post('/consentimento', [PatientController::class, 'acceptConsent'], ['auth', 'twofactor', 'consent']);
$router->get('/pacientes/{id:\d+}/acesso-profissionais', [AccessGrantController::class, 'index'], $authMw);
$router->post('/pacientes/{id:\d+}/acesso-profissionais', [AccessGrantController::class, 'grant'], $authMw);
$router->post('/acesso-profissionais/{grantId:\d+}/revogar', [AccessGrantController::class, 'revoke'], $authMw);
$router->get('/maioridade', [AccessGrantController::class, 'majorityIndex'], $authMw);
$router->post('/pacientes/{id:\d+}/maioridade', [AccessGrantController::class, 'decide'], $authMw);

$router->get('/pacientes/{id:\d+}/crescimento', [GrowthController::class, 'index'], $authMw);
$router->get('/pacientes/{id:\d+}/curvas.json', [GrowthController::class, 'curves'], $authMw);
$router->get('/pacientes/{id:\d+}/medidas/nova', [GrowthController::class, 'create'], $authMw);
$router->post('/pacientes/{id:\d+}/medidas/nova', [GrowthController::class, 'store'], $authMw);
$router->get('/medidas/{id:\d+}/corrigir', [GrowthController::class, 'edit'], $authMw);
$router->post('/medidas/{id:\d+}/corrigir', [GrowthController::class, 'update'], $authMw);
$router->post('/medidas/{id:\d+}/remover', [GrowthController::class, 'remove'], $authMw);
$router->get('/medidas/{id:\d+}/versoes', [GrowthController::class, 'versions'], $authMw);

$router->get('/pacientes/{id:\d+}/energia', [EnergyController::class, 'index'], $authMw);
$router->post('/pacientes/{id:\d+}/energia', [EnergyController::class, 'store'], $authMw);

$router->get('/pacientes/{id:\d+}/prontuario', [RecordController::class, 'index'], $authMw);
$router->get('/pacientes/{id:\d+}/prontuario/{kind}', [RecordController::class, 'form'], $authMw);
$router->post('/pacientes/{id:\d+}/prontuario/{kind}', [RecordController::class, 'save'], $authMw);
$router->get('/prontuario/{entryId:\d+}/revisar', [RecordController::class, 'reviseForm'], $authMw);
$router->post('/prontuario/{entryId:\d+}/revisar', [RecordController::class, 'revise'], $authMw);
$router->post('/prontuario/{entryId:\d+}/remover', [RecordController::class, 'remove'], $authMw);
$router->get('/prontuario/{entryId:\d+}/versoes', [RecordController::class, 'versions'], $authMw);
