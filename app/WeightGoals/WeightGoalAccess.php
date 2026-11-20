<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Unico ponto da regra de acesso a meta de peso: so equipe (medica ou nutricionista com o paciente liberado).
 * Familia, crianca e secretaria nunca veem a meta, mesmo tendo ANTROPOMETRIA.
 */
final class WeightGoalAccess
{
    public const STAFF = ['medico', 'nutricionista'];

    public static function canView(array $user, int $patientId): bool
    {
        return in_array($user['role'] ?? '', self::STAFF, true)
            && Container::gate()->check($user, 'view', Policy::ANTROPOMETRIA, $patientId);
    }

    public static function canEdit(array $user, int $patientId): bool
    {
        return in_array($user['role'] ?? '', self::STAFF, true)
            && Container::gate()->check($user, 'edit', Policy::ANTROPOMETRIA, $patientId);
    }

    /** 403 (com registro de negativa pelo Gate quando o papel e de equipe). */
    public static function authorize(array $user, string $action, int $patientId, Request $r): void
    {
        if (!in_array($user['role'] ?? '', self::STAFF, true)) {
            throw new HttpException(403, 'Acesso negado');
        }
        Container::gate()->authorize($user, $action, Policy::ANTROPOMETRIA, $patientId, $r);
    }
}
