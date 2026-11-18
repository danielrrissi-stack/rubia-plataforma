<?php
declare(strict_types=1);

namespace App\Core;

use App\Access\Gate;
use App\Access\PatientScope;
use App\Access\Policy;
use App\Audit\AuditLog;
use App\Auth\AuthService;
use App\Auth\TokenService;
use App\Backup\BackupService;
use App\Backup\Dumper;
use App\Energy\EnergyGoalRepository;
use App\Goals\EduCardRepository;
use App\Goals\GoalRepository;
use App\Growth\BloodPressure;
use App\Growth\BpReferenceRepository;
use App\Growth\GrowthCalculator;
use App\Growth\MeasurementRepository;
use App\Growth\WhoReferenceRepository;
use App\Labs\AttachmentStore;
use App\Labs\LabCatalogRepository;
use App\Labs\LabRepository;
use App\Medications\AdverseEventRepository;
use App\Medications\MedicationLogRepository;
use App\Medications\PrescriptionRepository;
use App\Nutrition\FoodRepository;
use App\Nutrition\MealPlanRepository;
use App\Nutrition\SnippetRepository;
use App\Nutrition\TemplateRepository;
use App\Patients\AccessGrantRepository;
use App\Patients\ConsentService;
use App\Patients\GuardianRepository;
use App\Patients\MajorityService;
use App\Patients\PatientRepository;
use App\Records\ClinicalEntryRepository;
use App\Users\UserRepository;

/** Servicos compartilhados (singletons preguicosos). Testes usam set()/reset(). */
final class Container
{
    /** @var array<string,object> */
    private static array $items = [];

    public static function set(string $name, object $obj): void
    {
        self::$items[$name] = $obj;
    }

    public static function reset(): void
    {
        self::$items = [];
    }

    public static function db(): Database
    {
        return self::$items['db'] ??= new Database((array) Config::get('db'));
    }

    public static function audit(): AuditLog
    {
        return self::$items['audit'] ??= new AuditLog(self::db());
    }

    public static function mailer(): Mailer
    {
        return self::$items['mailer'] ??= new Mailer(self::db(), (array) Config::get('mail', []));
    }

    public static function users(): UserRepository
    {
        return self::$items['users'] ??= new UserRepository(self::db());
    }

    public static function tokens(): TokenService
    {
        return self::$items['tokens'] ??= new TokenService(self::db());
    }

    public static function auth(): AuthService
    {
        return self::$items['auth'] ??= new AuthService(
            self::db(),
            self::users(),
            self::audit(),
            new Session(),
        );
    }

    public static function policy(): Policy
    {
        return self::$items['policy'] ??= new Policy();
    }

    public static function patientScope(): PatientScope
    {
        return self::$items['patientScope'] ??= new PatientScope(self::db());
    }

    public static function gate(): Gate
    {
        return self::$items['gate'] ??= new Gate(self::policy(), self::patientScope(), self::audit());
    }

    public static function backups(): BackupService
    {
        return self::$items['backups'] ??= new BackupService(
            self::db(),
            new Dumper(self::db()),
            ['storage_path' => (string) Config::get('storage_path')],
        );
    }

    public static function patients(): PatientRepository
    {
        return self::$items['patients'] ??= new PatientRepository(self::db(), self::audit());
    }

    public static function guardians(): GuardianRepository
    {
        return self::$items['guardians'] ??= new GuardianRepository(self::db(), self::audit());
    }

    public static function consents(): ConsentService
    {
        return self::$items['consents'] ??= new ConsentService(self::db(), self::audit());
    }

    public static function accessGrants(): AccessGrantRepository
    {
        return self::$items['accessGrants'] ??= new AccessGrantRepository(self::db(), self::audit());
    }

    public static function majority(): MajorityService
    {
        return self::$items['majority'] ??= new MajorityService(self::db(), self::audit(), self::guardians(), self::users());
    }

    public static function whoRef(): WhoReferenceRepository
    {
        return self::$items['whoRef'] ??= new WhoReferenceRepository(self::db());
    }

    public static function growth(): GrowthCalculator
    {
        return self::$items['growth'] ??= new GrowthCalculator(self::whoRef());
    }

    public static function bpRef(): BpReferenceRepository
    {
        return self::$items['bpRef'] ??= new BpReferenceRepository(self::db());
    }

    public static function measurements(): MeasurementRepository
    {
        return self::$items['measurements'] ??= new MeasurementRepository(self::db(), self::audit());
    }

    public static function energyGoals(): EnergyGoalRepository
    {
        return self::$items['energyGoals'] ??= new EnergyGoalRepository(self::db(), self::audit());
    }

    public static function clinicalEntries(): ClinicalEntryRepository
    {
        return self::$items['clinicalEntries'] ??= new ClinicalEntryRepository(self::db(), self::audit());
    }

    public static function bp(): BloodPressure
    {
        return self::$items['bp'] ??= new BloodPressure();
    }

    public static function foods(): FoodRepository
    {
        return self::$items['foods'] ??= new FoodRepository(self::db());
    }

    public static function mealPlans(): MealPlanRepository
    {
        return self::$items['mealPlans'] ??= new MealPlanRepository(self::db(), self::audit());
    }

    public static function mealPlanTemplates(): TemplateRepository
    {
        return self::$items['mealPlanTemplates'] ??= new TemplateRepository(self::db(), self::audit());
    }

    public static function textSnippets(): SnippetRepository
    {
        return self::$items['textSnippets'] ??= new SnippetRepository(self::db(), self::audit());
    }

    public static function labCatalog(): LabCatalogRepository
    {
        return self::$items['labCatalog'] ??= new LabCatalogRepository(self::db(), self::audit());
    }

    public static function labs(): LabRepository
    {
        return self::$items['labs'] ??= new LabRepository(self::db(), self::audit(), self::labCatalog(), self::patients());
    }

    public static function labAttachments(): AttachmentStore
    {
        return self::$items['labAttachments'] ??= new AttachmentStore(
            self::db(),
            self::audit(),
            rtrim((string) Config::get('storage_path', APP_ROOT . '/storage'), '/\\') . '/uploads',
        );
    }

    public static function goals(): GoalRepository
    {
        return self::$items['goals'] ??= new GoalRepository(self::db(), self::audit());
    }

    public static function prescriptions(): PrescriptionRepository
    {
        return self::$items['prescriptions'] ??= new PrescriptionRepository(self::db(), self::audit());
    }

    public static function medicationLogs(): MedicationLogRepository
    {
        return self::$items['medicationLogs'] ??= new MedicationLogRepository(self::db(), self::audit(), self::prescriptions());
    }

    public static function adverseEvents(): AdverseEventRepository
    {
        return self::$items['adverseEvents'] ??= new AdverseEventRepository(self::db(), self::audit(), self::prescriptions());
    }

    public static function eduCards(): EduCardRepository
    {
        return self::$items['eduCards'] ??= new EduCardRepository(self::db(), self::audit());
    }
}
