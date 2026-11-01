<?php
declare(strict_types=1);

namespace App\Core;

use App\Access\Gate;
use App\Access\PatientScope;
use App\Access\Policy;
use App\Audit\AuditLog;
use App\Auth\AuthService;
use App\Auth\TokenService;
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
}
