<?php
declare(strict_types=1);

namespace App\Dashboard;

use App\Access\Policy;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class DashboardController
{
    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $role = (string) $user['role'];
        if (in_array($role, ['paciente', 'responsavel', 'crianca'], true)) {
            return Response::redirect('/portal');
        }
        $today = date('Y-m-d');
        $gate = Container::gate();
        $ids = Container::patientScope()->accessiblePatientIds($user);
        $isDoctor = $gate->check($user, 'view', Policy::CLINICO, null);

        $recent = [];
        if ($ids === null) {
            $recent = Container::db()->all(
                'SELECT * FROM patients WHERE deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 10'
            );
        } elseif ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $recent = Container::db()->all(
                "SELECT * FROM patients WHERE deleted_at IS NULL AND id IN ($in) ORDER BY created_at DESC, id DESC LIMIT 10",
                $ids
            );
        }

        return Response::html(View::render('Dashboard/views/index.php', [
            'title' => 'Início',
            'userName' => $user['name'],
            'role' => $role,
            'today' => $today,
            'recent' => $recent,
            'pending' => $isDoctor ? Container::majority()->pending($today) : [],
            'canCreate' => $gate->check($user, 'edit', Policy::CADASTRO, null),
        ]));
    }
}
