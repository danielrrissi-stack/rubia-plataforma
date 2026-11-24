<?php
declare(strict_types=1);

namespace App\Goals;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Labs\Clinician;
use App\Users\UserRepository;

/**
 * Conta propria da crianca/adolescente (papel 'crianca', login por nome de usuario), gerida so pela medica na
 * ficha: criar, redefinir senha (provisoria, mostrada uma unica vez), desativar/reativar e a opcao de sigilo
 * "Responsaveis veem as marcacoes feitas pelo proprio paciente". A senha provisoria nunca vai para log/auditoria.
 */
final class ChildAccountController
{
    private const ACTIONS = ['criar', 'redefinir', 'desativar', 'reativar', 'sigilo'];
    /** Sem caracteres ambiguos (0/o, 1/l/i) e so minusculas: facil de ditar e digitar no celular. */
    private const TEMP_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const TEMP_LENGTH = 12;

    public function show(Request $r): Response
    {
        [$user, $patient] = $this->open($r);
        return $this->page($user, $patient, null, '');
    }

    public function update(Request $r): Response
    {
        [$user, $patient] = $this->open($r);
        $action = (string) $r->input('acao', '');
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->back($patient, 'Ação inválida.');
        }
        if ($action === 'criar') {
            return $this->create($r, $user, $patient);
        }
        $child = $patient['child_user_id'] === null ? null : Container::users()->find((int) $patient['child_user_id']);
        if ($child === null) {
            return $this->back($patient, 'Este paciente não tem conta própria.');
        }
        return match ($action) {
            'redefinir' => $this->resetPassword($r, $user, $patient, $child),
            'desativar', 'reativar' => $this->setActive($r, $user, $patient, $child, $action === 'reativar'),
            default => $this->setSecrecy($r, $user, $patient),
        };
    }

    private function create(Request $r, array $actor, array $patient): Response
    {
        $pid = (int) $patient['id'];
        $username = UserRepository::normalizeUsername((string) $r->input('usuario', ''));
        $users = Container::users();
        $error = null;
        if ($patient['child_user_id'] !== null) {
            $error = 'Este paciente já tem conta própria de criança.';
        } elseif ($patient['user_id'] !== null) {
            $error = 'Este paciente já tem acesso próprio (conta de paciente).';
        } elseif (!UserRepository::isValidUsername($username)) {
            $error = 'Nome de usuário inválido: use de 3 a 40 letras minúsculas, números ou ponto.';
        } elseif ($users->findByUsername($username) !== null) {
            $error = 'Este nome de usuário já está em uso.';
        }
        if ($error !== null) {
            return $this->back($patient, $error);
        }
        $temp = self::temporaryPassword();
        $db = Container::db();
        try {
            $created = $db->transaction(function () use ($db, $users, $patient, $pid, $username, $temp, $actor, $r): bool {
                // Trava a linha do paciente: duas abas criando ao mesmo tempo nao geram duas contas.
                $row = $db->one('SELECT user_id, child_user_id FROM patients WHERE id = ? FOR UPDATE', [$pid]);
                if ($row === null || $row['user_id'] !== null || $row['child_user_id'] !== null) {
                    return false;
                }
                $uid = $users->create([
                    'name' => $patient['social_name'] ?: $patient['full_name'],
                    'email' => null, 'username' => $username, 'role' => 'crianca', 'active' => 1,
                ]);
                $users->setTemporaryPassword($uid, $temp);
                $db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$uid, $pid]);
                Container::audit()->record((int) $actor['id'], 'create', 'users', $uid, $pid, null,
                    ['username' => $username, 'role' => 'crianca'], $r);
                return true;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') { // violacao de unicidade (usuario criado por outra aba)
                throw $e;
            }
            $created = false;
        }
        if (!$created) {
            return $this->back($patient, 'Não foi possível criar a conta: o paciente já tem acesso ou o usuário já existe.');
        }
        return $this->page($actor, $this->reload($pid), 'Conta criada. Entregue o usuário e a senha provisória à família.', $temp);
    }

    private function resetPassword(Request $r, array $actor, array $patient, array $child): Response
    {
        $pid = (int) $patient['id'];
        $temp = self::temporaryPassword();
        Container::users()->setTemporaryPassword((int) $child['id'], $temp);
        Container::audit()->record((int) $actor['id'], 'reset_password', 'users', (int) $child['id'], $pid, null,
            ['must_change_password' => 1], $r);
        return $this->page($actor, $patient, 'Senha redefinida. Entregue a nova senha provisória à família.', $temp);
    }

    private function setActive(Request $r, array $actor, array $patient, array $child, bool $active): Response
    {
        $uid = (int) $child['id'];
        if ((int) $child['active'] !== ($active ? 1 : 0)) {
            Container::users()->update($uid, ['active' => $active ? 1 : 0]);
            Container::audit()->record((int) $actor['id'], $active ? 'reactivate' : 'deactivate', 'users', $uid,
                (int) $patient['id'], ['active' => (int) $child['active']], ['active' => $active ? 1 : 0], $r);
        }
        Session::flash('ok', $active ? 'Conta reativada.' : 'Conta desativada. A criança não consegue mais entrar.');
        return Response::redirect($this->path($patient));
    }

    private function setSecrecy(Request $r, array $actor, array $patient): Response
    {
        $pid = (int) $patient['id'];
        $before = (int) $patient['guardians_see_child_checks'];
        $after = (string) $r->input('ver_marcacoes', '') === '1' ? 1 : 0;
        if ($before !== $after) {
            Container::db()->run('UPDATE patients SET guardians_see_child_checks = ? WHERE id = ?', [$after, $pid]);
            Container::audit()->record((int) $actor['id'], 'update', 'patients', $pid, $pid,
                ['guardians_see_child_checks' => $before], ['guardians_see_child_checks' => $after], $r);
        }
        Session::flash('ok', 'Configuração salva.');
        return Response::redirect($this->path($patient));
    }

    public static function temporaryPassword(): string
    {
        $max = strlen(self::TEMP_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::TEMP_LENGTH; $i++) {
            $out .= self::TEMP_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** So a medica (antes do Gate, como nas telas de exames) e com acesso ao paciente. @return array{0:array,1:array} */
    private function open(Request $r): array
    {
        $user = $r->attributes['user'];
        $pid = (int) $r->params['id'];
        Clinician::assert($user, $r, $pid);
        Container::gate()->authorize($user, 'edit', Policy::CLINICO, $pid, $r);
        return [$user, $this->reload($pid)];
    }

    private function reload(int $pid): array
    {
        $patient = Container::patients()->find($pid);
        if ($patient === null || $patient['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return $patient;
    }

    private function path(array $patient): string
    {
        return '/pacientes/' . (int) $patient['id'] . '/acesso-crianca';
    }

    private function back(array $patient, string $error): Response
    {
        Session::flash('erro', $error);
        return Response::redirect($this->path($patient));
    }

    /** $temp: senha provisoria recem-gerada, mostrada so nesta resposta (nao vai para sessao, log nem cache). */
    private function page(array $user, array $patient, ?string $notice, string $temp): Response
    {
        $child = $patient['child_user_id'] === null ? null : Container::users()->find((int) $patient['child_user_id']);
        $res = Response::html(View::render('Goals/views/child_account.php', [
            'title' => 'Conta da criança',
            'userName' => $user['name'],
            'active' => '/pacientes',
            'patient' => $patient,
            'child' => $child,
            'notice' => $notice,
            'temp' => $temp,
            'hasPatientAccount' => $patient['user_id'] !== null,
        ]));
        return $res->header('Cache-Control', 'no-store');
    }
}
