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
    private const PHONE_MAX = 30;

    public function index(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $this->authorize($r, $id);
        return $this->form($r, $this->patient($id), ['nome' => '', 'email' => '', 'parentesco' => '', 'celular' => ''], []);
    }

    public function add(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorize($r, $id);
        $patient = $this->patient($id);
        $name = trim((string) $r->input('nome', ''));
        $email = UserRepository::normalizeEmail((string) $r->input('email', ''));
        $rel = (string) $r->input('parentesco', '');
        $phone = trim((string) $r->input('celular', ''));
        $values = ['nome' => $name, 'email' => $email, 'parentesco' => $rel, 'celular' => $phone];

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
        if (mb_strlen($phone) > self::PHONE_MAX) {
            $errors[] = 'Celular: no máximo ' . self::PHONE_MAX . ' caracteres.';
        }
        if ($errors !== []) {
            return $this->form($r, $patient, $values, $errors);
        }

        $users = Container::users();
        Container::db()->transaction(function () use ($users, $existing, $name, $email, $rel, $phone, $id, $actor, $r): void {
            if ($existing === null) {
                $uid = $users->create(['name' => $name, 'email' => $email, 'role' => 'responsavel', 'password' => null, 'active' => 1, 'phone' => $phone === '' ? null : $phone]);
                Container::audit()->record((int) $actor['id'], 'create', 'users', $uid, $id, null,
                    ['email' => $email, 'role' => 'responsavel', 'phone' => $phone === '' ? null : $phone], $r);
                $user = $users->find($uid);
                $invite = true;
            } else {
                $uid = (int) $existing['id'];
                $user = $existing;
                $invite = $existing['password_hash'] === null;
                if ($phone !== '') {
                    $this->savePhone($uid, $existing['phone'] ?? null, $phone, $id, $actor, $r);
                }
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

    /** POST /responsaveis/{linkId}/celular — celular do responsavel (users.phone; vazio apaga). */
    public function phone(Request $r): Response
    {
        $link = Container::guardians()->find((int) $r->params['linkId']);
        if ($link === null || (int) $link['active'] !== 1) {
            throw new HttpException(404);
        }
        $pid = (int) $link['patient_id'];
        $actor = $this->authorize($r, $pid);
        $phone = trim((string) $r->input('celular', ''));
        if (mb_strlen($phone) > self::PHONE_MAX) {
            Session::flash('erro', 'Celular: no máximo ' . self::PHONE_MAX . ' caracteres.');
            return Response::redirect('/pacientes/' . $pid . '/responsaveis');
        }
        $uid = (int) $link['user_id'];
        $guardian = Container::users()->find($uid);
        if ($guardian === null) {
            throw new HttpException(404);
        }
        $this->savePhone($uid, $guardian['phone'] ?? null, $phone, $pid, $actor, $r);
        Session::flash('ok', 'Celular do responsável salvo.');
        return Response::redirect('/pacientes/' . $pid . '/responsaveis');
    }

    /** Grava users.phone (vazio = NULL) e audita `update` em users so com o telefone; nada quando nao mudou. */
    private function savePhone(int $uid, ?string $before, string $phone, int $patientId, array $actor, Request $r): void
    {
        $after = $phone === '' ? null : $phone;
        if ($after === $before) {
            return;
        }
        Container::db()->run('UPDATE users SET phone = ? WHERE id = ?', [$after, $uid]);
        Container::audit()->record((int) $actor['id'], 'update', 'users', $uid, $patientId, ['phone' => $before], ['phone' => $after], $r);
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
            'phoneMax' => self::PHONE_MAX,
        ]));
    }
}
