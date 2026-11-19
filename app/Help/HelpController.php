<?php
declare(strict_types=1);

namespace App\Help;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Manual de utilizacao. Cada perfil ve o proprio manual; a medica administradora abre os seis
 * (para treinar a equipe e mandar o PDF a familia). Secoes de administracao so para ela.
 */
final class HelpController
{
    private const FAMILY = ['responsavel', 'paciente', 'crianca'];

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        return $this->render($user, (string) $user['role']);
    }

    public function show(Request $r): Response
    {
        $user = $r->attributes['user'];
        $role = Manual::roleFromSlug((string) $r->params['perfil']);
        if ($role === null) {
            throw new HttpException(404);
        }
        if ($role !== $user['role'] && !self::isAdminDoctor($user)) {
            throw new HttpException(403, 'Acesso negado');
        }
        return $this->render($user, $role);
    }

    public static function isAdminDoctor(array $user): bool
    {
        return ($user['role'] ?? '') === 'medico' && (int) ($user['is_admin'] ?? 0) === 1;
    }

    private function render(array $user, string $role): Response
    {
        if (!in_array($role, Manual::ROLES, true)) {
            throw new HttpException(404);
        }
        $admin = self::isAdminDoctor($user);
        $portal = in_array($user['role'], self::FAMILY, true);
        $data = [
            'role' => $role, 'sections' => Manual::sections($role, $admin), 'isAdmin' => $admin,
            'title' => Manual::title($role), 'userName' => $user['name'], 'active' => '/ajuda',
        ];
        return Response::html(View::render('Help/views/index.php', $data,
            $portal ? 'Layout/views/layout_portal.php' : 'Layout/views/layout.php'));
    }
}
