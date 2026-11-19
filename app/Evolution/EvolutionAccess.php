<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;

/** Painel de evolucao: so a medica (precisa ver medidas, remedios e exames do paciente). */
final class EvolutionAccess
{
    private const VIEW = [Policy::ANTROPOMETRIA, Policy::MEDICAMENTOS, Policy::EXAMES];
    private const EDIT = [Policy::ANTROPOMETRIA, Policy::MEDICAMENTOS];

    public static function canView(array $user, int $patientId): bool
    {
        return self::all($user, 'view', self::VIEW, $patientId);
    }

    public static function canEdit(array $user, int $patientId): bool
    {
        return self::canView($user, $patientId) && self::all($user, 'edit', self::EDIT, $patientId);
    }

    /** 403 para quem nao e medica; para a medica, o Gate registra a negativa se faltar alguma secao. */
    public static function authorize(array $user, string $action, int $patientId, Request $r): void
    {
        if (($user['role'] ?? '') !== 'medico') {
            throw new HttpException(403, 'Acesso negado');
        }
        foreach (self::VIEW as $section) {
            Container::gate()->authorize($user, 'view', $section, $patientId, $r);
        }
        if ($action === 'edit') {
            foreach (self::EDIT as $section) {
                Container::gate()->authorize($user, 'edit', $section, $patientId, $r);
            }
        }
    }

    private static function all(array $user, string $action, array $sections, int $patientId): bool
    {
        if (($user['role'] ?? '') !== 'medico') {
            return false;
        }
        foreach ($sections as $section) {
            if (!Container::gate()->check($user, $action, $section, $patientId)) {
                return false;
            }
        }
        return true;
    }
}
