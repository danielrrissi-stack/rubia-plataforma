<?php
declare(strict_types=1);

namespace App\Labs;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Telas de exames da equipe sao so do medico. A checagem de papel vem ANTES do Gate: a familia ganha
 * 'view EXAMES' para o portal e, sem ela, abriria as telas da equipe.
 */
final class Clinician
{
    public static function is(array $user): bool
    {
        return ($user['role'] ?? '') === 'medico';
    }

    /** @throws HttpException 403 (e audita 'denied', como o Gate) */
    public static function assert(array $user, Request $r, ?int $patientId = null): void
    {
        if (self::is($user)) {
            return;
        }
        Container::audit()->record(
            isset($user['id']) ? (int) $user['id'] : null,
            'denied',
            'lab_collections',
            null,
            $patientId,
            null,
            ['acao' => 'equipe', 'section' => 'exames'],
            $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }
}
