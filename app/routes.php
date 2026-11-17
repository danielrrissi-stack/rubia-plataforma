<?php
declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Auth\AuthController;
use App\Auth\TwoFactorController;
use App\Backup\BackupController;
use App\Dashboard\DashboardController;
use App\Energy\EnergyController;
use App\Nutrition\FoodController;
use App\Nutrition\MealPlanController;
use App\Nutrition\TemplateController;
use App\Growth\GrowthController;
use App\Patients\AccessGrantController;
use App\Patients\GuardianController;
use App\Patients\PatientController;
use App\Install\InstallController;
use App\Labs\LabCatalogController;
use App\Labs\LabController;
use App\Portal\PortalController;
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
$router->post('/equipe/{id:\d+}/redefinir-2fa', [UserController::class, 'resetTwoFactor'], $staffMw);

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

$router->get('/pacientes/{id:\d+}/plano', [MealPlanController::class, 'index'], $authMw);
$router->post('/pacientes/{id:\d+}/plano/novo', [MealPlanController::class, 'create'], $authMw);
$router->get('/alimentos/busca', [FoodController::class, 'search'], $authMw);
$router->get('/alimentos', [FoodController::class, 'index'], $authMw);
$router->get('/alimentos/novo', [FoodController::class, 'createForm'], $authMw);
$router->post('/alimentos/novo', [FoodController::class, 'create'], $authMw);
$router->get('/alimentos/{foodId:\d+}/editar', [FoodController::class, 'editForm'], $authMw);
$router->post('/alimentos/{foodId:\d+}/editar', [FoodController::class, 'update'], $authMw);
$router->post('/alimentos/{foodId:\d+}/medidas', [FoodController::class, 'addMeasure'], $authMw);
$router->post('/alimentos/medidas/{measureId:\d+}/editar', [FoodController::class, 'updateMeasure'], $authMw);
$router->post('/alimentos/{foodId:\d+}/ativo', [FoodController::class, 'toggle'], $authMw);
$router->get('/modelos-plano', [TemplateController::class, 'index'], $authMw);
$router->get('/modelos-plano/novo', [TemplateController::class, 'createForm'], $authMw);
$router->post('/modelos-plano/novo', [TemplateController::class, 'create'], $authMw);
$router->get('/modelos-plano/{templateId:\d+}/editar', [TemplateController::class, 'edit'], $authMw);
$router->post('/modelos-plano/{templateId:\d+}/editar', [TemplateController::class, 'update'], $authMw);
$router->post('/modelos-plano/{templateId:\d+}/excluir', [TemplateController::class, 'delete'], $authMw);
$router->post('/planos/{planId:\d+}/salvar-como-modelo', [TemplateController::class, 'saveFromPlan'], $authMw);
$router->get('/trechos', [TemplateController::class, 'snippets'], $authMw);
$router->get('/trechos.json', [TemplateController::class, 'snippetsJson'], $authMw);
$router->get('/trechos/novo', [TemplateController::class, 'snippetCreateForm'], $authMw);
$router->post('/trechos/novo', [TemplateController::class, 'snippetCreate'], $authMw);
$router->get('/trechos/{snippetId:\d+}/editar', [TemplateController::class, 'snippetEditForm'], $authMw);
$router->post('/trechos/{snippetId:\d+}/editar', [TemplateController::class, 'snippetUpdate'], $authMw);
$router->post('/trechos/{snippetId:\d+}/excluir', [TemplateController::class, 'snippetDelete'], $authMw);
$router->post('/planos/previa',[MealPlanController::class, 'preview'], $authMw);
$router->get('/planos/{planId:\d+}', [MealPlanController::class, 'show'], $authMw);
$router->get('/planos/{planId:\d+}/imprimir', [MealPlanController::class, 'print'], $authMw);
$router->get('/planos/{planId:\d+}/editar', [MealPlanController::class, 'edit'], $authMw);
$router->post('/planos/{planId:\d+}/editar', [MealPlanController::class, 'save'], $authMw);
$router->post('/planos/{planId:\d+}/publicar', [MealPlanController::class, 'publish'], $authMw);
$router->post('/planos/{planId:\d+}/encerrar', [MealPlanController::class, 'end'], $authMw);
$router->post('/planos/{planId:\d+}/descartar', [MealPlanController::class, 'discard'], $authMw);
$router->post('/planos/{planId:\d+}/novo-rascunho', [MealPlanController::class, 'newDraft'], $authMw);

$router->get('/pacientes/{id:\d+}/prontuario',[RecordController::class, 'index'], $authMw);
$router->get('/pacientes/{id:\d+}/prontuario/{kind}', [RecordController::class, 'form'], $authMw);
$router->post('/pacientes/{id:\d+}/prontuario/{kind}', [RecordController::class, 'save'], $authMw);
$router->get('/prontuario/{entryId:\d+}/revisar', [RecordController::class, 'reviseForm'], $authMw);
$router->post('/prontuario/{entryId:\d+}/revisar', [RecordController::class, 'revise'], $authMw);
$router->post('/prontuario/{entryId:\d+}/remover', [RecordController::class, 'remove'], $authMw);
$router->get('/prontuario/{entryId:\d+}/versoes', [RecordController::class, 'versions'], $authMw);

$router->get('/pacientes/{id:\d+}/exames', [LabController::class, 'index'], $authMw);
$router->get('/pacientes/{id:\d+}/exames/serie.json', [LabController::class, 'series'], $authMw);
$router->get('/pacientes/{id:\d+}/exames/nova', [LabController::class, 'createForm'], $authMw);
$router->post('/pacientes/{id:\d+}/exames/nova', [LabController::class, 'create'], $authMw);
$router->get('/exames/anexos/{attachmentId:\d+}', [LabController::class, 'download'], $authMw);
$router->post('/exames/anexos/{attachmentId:\d+}/excluir', [LabController::class, 'deleteAttachment'], $authMw);
$router->get('/exames/{collectionId:\d+}', [LabController::class, 'show'], $authMw);
$router->get('/exames/{collectionId:\d+}/editar', [LabController::class, 'editForm'], $authMw);
$router->post('/exames/{collectionId:\d+}/editar', [LabController::class, 'update'], $authMw);
$router->post('/exames/{collectionId:\d+}/excluir', [LabController::class, 'delete'], $authMw);
$router->post('/exames/{collectionId:\d+}/liberar', [LabController::class, 'release'], $authMw);
$router->post('/exames/{collectionId:\d+}/recolher', [LabController::class, 'unrelease'], $authMw);
$router->post('/exames/{collectionId:\d+}/anexos', [LabController::class, 'addAttachments'], $authMw);
$router->get('/catalogo-exames', [LabCatalogController::class, 'index'], $authMw);
$router->get('/catalogo-exames/novo', [LabCatalogController::class, 'createForm'], $authMw);
$router->post('/catalogo-exames/novo', [LabCatalogController::class, 'create'], $authMw);
$router->get('/catalogo-exames/{testId:\d+}/editar', [LabCatalogController::class, 'editForm'], $authMw);
$router->post('/catalogo-exames/{testId:\d+}/editar', [LabCatalogController::class, 'update'], $authMw);
$router->post('/catalogo-exames/{testId:\d+}/confirmar', [LabCatalogController::class, 'confirm'], $authMw);

$router->get('/portal', [PortalController::class, 'index'], $authMw);
$router->get('/portal/p/{id:\d+}', [PortalController::class, 'home'], $authMw);
$router->get('/portal/p/{id:\d+}/crescimento', [PortalController::class, 'growth'], $authMw);
$router->get('/portal/p/{id:\d+}/alimentacao', [PortalController::class, 'food'], $authMw);
$router->get('/portal/p/{id:\d+}/alimentacao/imprimir', [PortalController::class, 'foodPrint'], $authMw);
$router->get('/portal/p/{id:\d+}/medidas/nova', [PortalController::class, 'measureForm'], $authMw);
$router->post('/portal/p/{id:\d+}/medidas/nova', [PortalController::class, 'measureStore'], $authMw);
$router->get('/portal/conta', [PortalController::class, 'account'], $authMw);
$router->post('/portal/conta/senha', [PortalController::class, 'changePassword'], $authMw);
