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
use App\Users\UserRepository;

final class GuardianController
{
    public const RELATIONSHIPS = ['mãe', 'pai', 'avó', 'avô', 'tutor legal', 'outro'];

    public function index(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $this->authorize($r, $id);
        return $this->form($r, $this->patient($id), ['nome' => '', 'email' => '', 'parentesco' => ''], []);
    }

    public function add(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorize($r, $id);
        $patient = $this->patient($id);
        $name = trim((string) $r->input('nome', ''));
        $email = UserRepository::normalizeEmail((string) $r->input('email', ''));
        $rel = (string) $r->input('parentesco', '');
        $values = ['nome' => $name, 'email' => $email, 'parentesco' => $rel];

        $errors = [];
        $existing = null;
        if (!in_array($rel, self::RELATIONSHIPS, true)) {
            $errors[] = 'Selecione o parentesco.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Informe um e-mail válido.';
        } else {
            $existing = Container::users()->findByEmail($email);
            if ($existing !== null && $existing['role'] !== 'responsavel') {
                $errors[] = 'Este e-mail já pertence a outro tipo de conta.';
            } elseif ($existing !== null && (int) $existing['active'] !== 1) {
                $errors[] = 'Esta conta está desativada. Reative-a antes de vincular.';
            } elseif ($existing !== null && Container::guardians()->activeLink($id, (int) $existing['id']) !== null) {
                $errors[] = 'Este responsável já está vinculado ao paciente.';
            }
        }
        if ($existing === null && ($name === '' || mb_strlen($name) > 150)) {
            $errors[] = 'Informe o nome do responsável (até 150 caracteres).';
        }
        if ($errors !== []) {
            return $this->form($r, $patient, $values, $errors);
        }

        $users = Container::users();
        Container::db()->transaction(function () use ($users, $existing, $name, $email, $rel, $id, $actor, $r): void {
            if ($existing === null) {
                $uid = $users->create(['name' => $name, 'email' => $email, 'role' => 'responsavel', 'password' => null, 'active' => 1]);
                Container::audit()->record((int) $actor['id'], 'create', 'users', $uid, $id, null,
                    ['email' => $email, 'role' => 'responsavel'], $r);
                $user = $users->find($uid);
                $invite = true;
            } else {
                $uid = (int) $existing['id'];
                $user = $existing;
                $invite = $existing['password_hash'] === null;
            }
            Container::guardians()->link($id, $uid, $rel, (int) $actor['id'], $r);
            if ($invite) {
                FamilyInvites::send($user);
            }
        });
        Session::flash('ok', $existing !== null
            ? 'Conta existente vinculada ao paciente (o nome digitado não foi aplicado).'
            : 'Responsável vinculado.');
        return Response::redirect('/pacientes/' . $id . '/responsaveis');
    }

    public function remove(Request $r): Response
    {
        $link = Container::guardians()->find((int) $r->params['linkId']);
        if ($link === null) {
            throw new HttpException(404);
        }
        $pid = (int) $link['patient_id'];
        $actor = $this->authorize($r, $pid);
        Container::guardians()->unlink((int) $link['id'], (int) $actor['id'], $r);
        Session::flash('ok', 'Vínculo removido.');
        return Response::redirect('/pacientes/' . $pid . '/responsaveis');
    }

    private function authorize(Request $r, int $patientId): array
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'edit', Policy::CADASTRO, $patientId, $r);
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

    private function form(Request $r, array $patient, array $values, array $errors): Response
    {
        $user = $r->attributes['user'];
        return Response::html(View::render('Patients/views/guardians.php', [
            'title' => 'Responsáveis',
            'userName' => $user['name'],
            'active' => '/pacientes',
            'patient' => $patient,
            'guardians' => Container::guardians()->forPatient((int) $patient['id']),
            'values' => $values,
            'errors' => $errors,
            'relationships' => self::RELATIONSHIPS,
        ]));
    }
}
