<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;

/** Telas de plano alimentar sao da equipe (medico/nutricionista); familia usa o portal. */
final class Staff
{
    private const ROLES = ['medico', 'nutricionista'];

    public static function is(array $user): bool
    {
        return in_array($user['role'] ?? '', self::ROLES, true);
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
            'nutricao',
            null,
            $patientId,
            null,
            ['acao' => 'equipe'],
            $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }
}
