<?php
declare(strict_types=1);

namespace App\Patients;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;

/** Liberacao de nutricionistas por paciente e decisoes de maioridade (somente medico). */
final class AccessGrantController
{
    public function index(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $this->authorize($r, $id);
        $patient = $this->patient($id);
        $user = $r->attributes['user'];
        return Response::html(View::render('Patients/views/access.php', [
            'title' => 'Acesso de profissionais',
            'userName' => $user['name'],
            'active' => '/pacientes',
            'patient' => $patient,
            'grants' => Container::accessGrants()->activeFor($id),
            'available' => Container::accessGrants()->available($id),
        ]));
    }

    public function grant(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorize($r, $id);
        $this->patient($id);
        $prof = (int) $r->input('profissional', 0);
        $valid = $prof > 0 && Container::db()->one(
            "SELECT 1 FROM users WHERE id = ? AND role = 'nutricionista' AND active = 1",
            [$prof]
        ) !== null;
        if (!$valid) {
            Session::flash('erro', 'Selecione um nutricionista ativo.');
        } else {
            Container::accessGrants()->grant($id, $prof, (int) $actor['id'], $r);
            Session::flash('ok', 'Acesso liberado.');
        }
        return Response::redirect('/pacientes/' . $id . '/acesso-profissionais');
    }

    public function revoke(Request $r): Response
    {
        $grant = Container::accessGrants()->find((int) $r->params['grantId']);
        if ($grant === null) {
            throw new HttpException(404);
        }
        $pid = (int) $grant['patient_id'];
        $actor = $this->authorize($r, $pid);
        Container::accessGrants()->revoke((int) $grant['id'], (int) $actor['id'], $r);
        Session::flash('ok', 'Acesso revogado.');
        return Response::redirect('/pacientes/' . $pid . '/acesso-profissionais');
    }

    public function majorityIndex(Request $r): Response
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'view', Policy::CLINICO, null, $r);
        return Response::html(View::render('Patients/views/majority.php', [
            'title' => 'Decisões de maioridade',
            'userName' => $user['name'],
            'active' => '/maioridade',
            'pending' => Container::majority()->pending(date('Y-m-d')),
            'today' => date('Y-m-d'),
        ]));
    }

    public function decide(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorize($r, $id);
        $this->patient($id);
        try {
            Container::majority()->decide(
                $id,
                (string) $r->input('decisao', ''),
                (string) $r->input('observacoes', ''),
                (int) $actor['id'],
                (string) $r->input('email', ''),
                $r
            );
            Session::flash('ok', 'Decisão registrada.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/maioridade');
    }

    private function authorize(Request $r, int $patientId): array
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'edit', Policy::CLINICO, $patientId, $r);
        return $user;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }
}
