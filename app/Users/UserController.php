<?php
declare(strict_types=1);

namespace App\Users;

use App\Access\Policy;
use App\Auth\AuthController;
use App\Core\Config;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Gestao da equipe (medico/nutricionista/secretaria). Somente administradores. */
final class UserController
{
    public const ROLES = [
        'medico' => 'Médico(a)',
        'nutricionista' => 'Nutricionista',
        'secretaria' => 'Secretária',
    ];

    private const AUDIT_EXCLUDE = ['password_hash', 'totp_secret', 'failed_logins', 'locked_until'];

    public function index(Request $r): Response
    {
        $this->authorize($r, 'view');
        return $this->page('Users/views/index.php', 'Equipe', $r, ['users' => Container::users()->listStaff(), 'roles' => self::ROLES]);
    }

    public function create(Request $r): Response
    {
        $this->authorize($r, 'edit');
        return $this->form($r, null, self::blank(), []);
    }

    public function store(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        [$data, $errors] = $this->validate($r, null, $actor);
        if ($errors !== []) {
            return $this->form($r, null, $this->posted($r), $errors);
        }
        $users = Container::users();
        $id = $users->create($data + ['password' => null, 'active' => 1]);
        Container::audit()->record((int) $actor['id'], 'create', 'users', $id, null, null, $this->scrub($users->find($id)), $r);
        $this->sendInvite($users->find($id));
        Session::flash('ok', 'Usuário criado. Convite enviado por e-mail.');
        return Response::redirect('/equipe');
    }

    public function edit(Request $r): Response
    {
        $this->authorize($r, 'edit');
        $user = $this->staff($r);
        return $this->form($r, $user, $this->fromRow($user), []);
    }

    public function update(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        $user = $this->staff($r);
        [$data, $errors] = $this->validate($r, $user, $actor);
        if ($errors !== []) {
            return $this->form($r, $user, $this->posted($r), $errors);
        }
        Container::users()->update((int) $user['id'], $data);
        $after = Container::users()->find((int) $user['id']);
        Container::audit()->record((int) $actor['id'], 'update', 'users', (int) $user['id'], null, $this->scrub($user), $this->scrub($after), $r);
        Session::flash('ok', 'Usuário atualizado.');
        return Response::redirect('/equipe');
    }

    public function deactivate(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        $user = $this->staff($r);
        if ((int) $user['id'] === (int) $actor['id']) {
            Session::flash('erro', 'Você não pode desativar o seu próprio usuário.');
            return Response::redirect('/equipe');
        }
        return $this->setActive($r, $actor, $user, 0, 'Usuário desativado.');
    }

    public function reactivate(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        return $this->setActive($r, $actor, $this->staff($r), 1, 'Usuário reativado.');
    }

    public function resendInvite(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        $user = $this->staff($r);
        if ($user['password_hash'] !== null || (int) $user['active'] !== 1) {
            Session::flash('erro', 'Este usuário já definiu a senha ou está inativo; o convite não foi reenviado.');
            return Response::redirect('/equipe');
        }
        $this->sendInvite($user);
        Container::audit()->record((int) $actor['id'], 'update', 'users', (int) $user['id'], null, null, ['convite_reenviado' => true], $r);
        Session::flash('ok', 'Convite reenviado.');
        return Response::redirect('/equipe');
    }

    /**
     * Celular perdido: desativa o 2FA do usuario de equipe; no proximo login ele e obrigado
     * a cadastrar um novo aplicativo. Nao vale para o proprio admin (ver README "Perdi o celular").
     */
    public function resetTwoFactor(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        $user = $this->staff($r);
        if ((int) $user['id'] === (int) $actor['id']) {
            Session::flash('erro', 'Você não pode redefinir a sua própria verificação em duas etapas por aqui.');
            return Response::redirect('/equipe');
        }
        Container::users()->update((int) $user['id'], ['totp_enabled' => 0, 'totp_secret' => null]);
        Container::audit()->record((int) $actor['id'], 'update', 'users', (int) $user['id'], null, null, ['totp_enabled' => 0], $r);
        Session::flash('ok', '2FA redefinido. O usuário deverá configurar um novo aplicativo no próximo acesso.');
        return Response::redirect('/equipe');
    }

    private function setActive(Request $r, array $actor, array $user, int $active, string $msg): Response
    {
        Container::users()->update((int) $user['id'], ['active' => $active]);
        $after = Container::users()->find((int) $user['id']);
        Container::audit()->record((int) $actor['id'], 'update', 'users', (int) $user['id'], null, $this->scrub($user), $this->scrub($after), $r);
        Session::flash('ok', $msg);
        return Response::redirect('/equipe');
    }

    /** @return array{0:array,1:string[]} dados validados e erros */
    private function validate(Request $r, ?array $existing, array $actor): array
    {
        $users = Container::users();
        $name = trim((string) $r->input('nome', ''));
        $email = UserRepository::normalizeEmail((string) $r->input('email', ''));
        $role = (string) $r->input('papel', '');
        $register = trim((string) $r->input('registro', ''));
        $isAdmin = $role === 'medico' && (string) $r->input('administrador', '') !== '' ? 1 : 0;
        $errors = [];

        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Informe o nome (até 150 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Informe um e-mail válido.';
        } else {
            $dup = $users->findByEmail($email);
            if ($dup !== null && (int) $dup['id'] !== (int) ($existing['id'] ?? 0)) {
                $errors[] = 'Já existe um usuário com este e-mail.';
            }
        }
        if (!isset(self::ROLES[$role])) {
            $errors[] = 'Selecione um papel válido.';
        } elseif ($role !== 'secretaria' && $register === '') {
            $errors[] = 'Informe o registro profissional (CRM/CRN).';
        }
        if (mb_strlen($register) > 30) {
            $errors[] = 'O registro profissional deve ter até 30 caracteres.';
        }
        if ($existing !== null && (int) $existing['id'] === (int) $actor['id']) {
            if ((int) $existing['is_admin'] === 1 && $isAdmin === 0) {
                $errors[] = 'Você não pode remover o seu próprio acesso de administrador.';
            }
        }

        return [[
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'professional_register' => $register === '' ? null : $register,
            'is_admin' => $isAdmin,
        ], $errors];
    }

    private function sendInvite(array $user): void
    {
        $token = Container::tokens()->issue((int) $user['id'], 'invite', AuthController::INVITE_TTL_MIN);
        $link = rtrim((string) Config::get('base_url', ''), '/') . '/senha/definir/' . $token;
        $role = self::ROLES[$user['role']] ?? $user['role'];
        $html = '<p>Olá, ' . e($user['name']) . '.</p>'
            . '<p>Você foi convidado(a) para a Plataforma Dra. Rubia com o papel de <strong>' . e($role) . '</strong>.</p>'
            . '<p>Para definir sua senha e acessar, use o link abaixo (válido por 72 horas):</p>'
            . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>O link expira em 72 horas. Se você não esperava este convite, ignore este e-mail.</p>';
        Container::mailer()->queue($user['email'], 'Convite — Plataforma Dra. Rubia', $html);
    }

    private function authorize(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, $action, Policy::USUARIOS, null, $r);
        return $user;
    }

    /** Usuario de equipe pelo {id}; contas de familia nao sao acessiveis aqui. */
    private function staff(Request $r): array
    {
        $user = Container::users()->find((int) $r->params['id']);
        if ($user === null || !isset(self::ROLES[$user['role']])) {
            throw new HttpException(404);
        }
        return $user;
    }

    private function scrub(?array $row): ?array
    {
        return $row === null ? null : array_diff_key($row, array_flip(self::AUDIT_EXCLUDE));
    }

    private static function blank(): array
    {
        return ['nome' => '', 'email' => '', 'papel' => '', 'registro' => '', 'administrador' => false];
    }

    private function fromRow(array $u): array
    {
        return [
            'nome' => $u['name'], 'email' => $u['email'], 'papel' => $u['role'],
            'registro' => (string) $u['professional_register'], 'administrador' => (int) $u['is_admin'] === 1,
        ];
    }

    private function posted(Request $r): array
    {
        return [
            'nome' => (string) $r->input('nome', ''), 'email' => (string) $r->input('email', ''),
            'papel' => (string) $r->input('papel', ''), 'registro' => (string) $r->input('registro', ''),
            'administrador' => (string) $r->input('administrador', '') !== '',
        ];
    }

    private function form(Request $r, ?array $user, array $values, array $errors): Response
    {
        return $this->page('Users/views/form.php', $user === null ? 'Novo usuário' : 'Editar usuário', $r, [
            'user' => $user, 'values' => $values, 'errors' => $errors, 'roles' => self::ROLES,
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        $user = $r->attributes['user'];
        return Response::html(View::render($template, $data + [
            'title' => $title,
            'userName' => $user['name'],
            'active' => '/equipe',
            'me' => (int) $user['id'],
        ]));
    }
}
