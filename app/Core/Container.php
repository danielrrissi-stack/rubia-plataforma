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
use App\Growth\BloodPressure;
use App\Growth\BpReferenceRepository;
use App\Growth\GrowthCalculator;
use App\Growth\MeasurementRepository;
use App\Growth\WhoReferenceRepository;
use App\Patients\AccessGrantRepository;
use App\Patients\ConsentService;
use App\Patients\GuardianRepository;
use App\Patients\MajorityService;
use App\Patients\PatientRepository;
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

    public static function bp(): BloodPressure
    {
        return self::$items['bp'] ??= new BloodPressure();
    }
}
