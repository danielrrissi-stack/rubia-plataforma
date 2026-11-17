<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Container;

/**
 * Regras da guarda global 'childarea' (app/middleware.php):
 * - senha provisoria (must_change_password = 1, qualquer papel): so /senha/trocar e o logout;
 * - papel 'crianca': so a area de metas e os cartoes do proprio paciente, a propria conta, /senha/trocar e o logout.
 */
final class ChildArea
{
    public const CHANGE_PASSWORD = '/senha/trocar';

    public static function isLogout(string $method, string $path): bool
    {
        return $method === 'POST' && $path === '/logout';
    }

    /** Liberado com senha provisoria pendente. */
    public static function allowsWithPendingPassword(string $method, string $path): bool
    {
        return $path === self::CHANGE_PASSWORD || self::isLogout($method, $path);
    }

    /** Caminho que o papel 'crianca' pode acessar; $patientId = paciente vinculado (null = nenhum). */
    public static function allows(string $method, string $path, ?int $patientId): bool
    {
        if (self::allowsWithPendingPassword($method, $path)
            || $path === '/portal/conta' || str_starts_with($path, '/portal/conta/')) {
            return true;
        }
        if ($patientId === null) {
            return $method === 'GET' && $path === '/portal'; // mostra "acesso ainda nao disponivel"
        }
        $base = '/portal/p/' . $patientId;
        return $path === $base . '/metas'
            || str_starts_with($path, $base . '/metas/')
            || str_starts_with($path, $base . '/cartoes/');
    }

    /** Area da crianca: as metas do paciente vinculado ou, sem vinculo, o /portal. */
    public static function home(?int $patientId): string
    {
        return $patientId === null ? '/portal' : '/portal/p/' . $patientId . '/metas';
    }

    /** Paciente vinculado a conta de crianca (patients.child_user_id, nao excluido). */
    public static function patientIdFor(array $user): ?int
    {
        $ids = Container::patientScope()->accessiblePatientIds($user) ?? [];
        return $ids === [] ? null : $ids[0];
    }
}
