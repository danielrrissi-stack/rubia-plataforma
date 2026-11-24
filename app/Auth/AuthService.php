<?php
declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Users\UserRepository;

final class AuthService
{
    public const GENERIC_ERROR = 'E-mail ou senha inválidos.';
    public const LOCKED_ERROR = 'Conta bloqueada temporariamente. Tente novamente em 15 minutos.';
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;
    private const STAFF_ROLES = ['medico', 'nutricionista', 'secretaria'];

    /** @var callable */
    private $now;

    public function __construct(
        private Database $db,
        private UserRepository $users,
        private AuditLog $audit,
        private Session $session,
        ?callable $now = null,
    ) {
        $this->now = $now ?? 'time';
    }

    private function now(): int
    {
        return (int) ($this->now)();
    }

    /** $login: e-mail ou nome de usuario (UserRepository::findByLogin). */
    public function attempt(string $login, string $password, Request $r): LoginResult
    {
        $user = $this->users->findByLogin($login);
        if ($user === null) {
            // Gasta tempo de hash para nao revelar a existencia da conta.
            password_verify($password, '$2y$10$usesomesillystringforsalt$');
            $this->audit->record(null, 'login_failed', 'users', null, null, null, null, $r);
            return new LoginResult(false, false, self::GENERIC_ERROR);
        }
        $id = (int) $user['id'];

        if ((int) $user['active'] !== 1) {
            $this->audit->record($id, 'login_failed', 'users', $id, null, null, ['motivo' => 'inativo'], $r);
            return new LoginResult(false, false, self::GENERIC_ERROR);
        }

        $locked = $this->db->one('SELECT 1 AS l FROM users WHERE id = ? AND locked_until > NOW()', [$id]);
        if ($locked !== null) {
            $this->audit->record($id, 'login_failed', 'users', $id, null, null, ['motivo' => 'bloqueado'], $r);
            return new LoginResult(false, false, self::LOCKED_ERROR);
        }

        $hash = $user['password_hash'];
        if ($hash === null || !password_verify($password, (string) $hash)) {
            $this->registerFailure($id);
            $this->audit->record($id, 'login_failed', 'users', $id, null, null, ['motivo' => 'senha'], $r);
            return new LoginResult(false, false, self::GENERIC_ERROR);
        }

        if ((int) $user['totp_enabled'] === 1) {
            // Contadores so zeram em completeLogin(): senha certa nao apaga tentativas de TOTP erradas.
            Session::regenerate();
            // Nunca manter login completo de A junto com pendencia de B.
            Session::forget('uid');
            Session::forget('last_activity');
            Session::forget('totp_setup_secret');
            Session::forget('totp_reauth');
            Session::forget('totp_attempts');
            Session::set('pending_2fa_user', $id);
            return new LoginResult(true, true);
        }

        $this->completeLogin($id, $r);
        return new LoginResult(true, false);
    }

    /** Conta uma falha (senha ou TOTP); a 5a falha bloqueia a conta por LOCK_MINUTES. */
    public function registerFailure(int $id): void
    {
        $this->db->run('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$id]);
        $this->db->run(
            'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), failed_logins = 0
             WHERE id = ? AND failed_logins >= ?',
            [self::LOCK_MINUTES, $id, self::MAX_FAILURES]
        );
    }

    public function isLocked(int $id): bool
    {
        return $this->db->one('SELECT 1 AS l FROM users WHERE id = ? AND locked_until > NOW()', [$id]) !== null;
    }

    /** Conclui o login (apos senha, ou apos 2FA na Task 5). */
    public function completeLogin(int $userId, Request $r): void
    {
        Session::regenerate();
        Session::forget('pending_2fa_user');
        Session::forget('_csrf'); // rotaciona o token CSRF (novo e gerado sob demanda)
        Session::forget('totp_setup_secret');
        Session::forget('totp_reauth');
        Session::forget('totp_attempts');
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$userId]);
        Session::set('uid', $userId);
        $this->refreshPasswordStamp($userId);
        Session::set('last_activity', $this->now());
        $this->db->run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
        $this->audit->record($userId, 'login', 'users', $userId, null, null, null, $r);
    }

    /** Usuario logado ou null; aplica timeout ocioso. */
    public function user(?Request $r = null): ?array
    {
        $uid = Session::get('uid');
        if (!is_int($uid)) {
            return null;
        }
        $user = $this->users->find($uid);
        if ($user === null || (int) $user['active'] !== 1) {
            $this->logout($r);
            return null;
        }
        // Senha trocada (ex.: redefinida pela medica) derruba as sessoes abertas antes da troca.
        if (!hash_equals(self::passwordStamp($user['password_hash'] ?? null), (string) Session::get('pw_stamp', ''))) {
            $this->logout($r);
            Session::flash('erro', 'Sua senha foi alterada. Entre de novo.');
            return null;
        }
        $minutes = in_array($user['role'], self::STAFF_ROLES, true)
            ? (int) Config::get('session.staff_idle_minutes', 30)
            : (int) Config::get('session.family_idle_minutes', 120);
        $last = (int) Session::get('last_activity', 0);
        if ($this->now() - $last > $minutes * 60) {
            $this->logout($r);
            Session::flash('erro', 'Sua sessão expirou por inatividade.');
            return null;
        }
        Session::set('last_activity', $this->now());
        return $user;
    }

    /** Guarda na sessao a impressao da senha atual; chamar apos o proprio usuario trocar a senha. */
    public function refreshPasswordStamp(int $userId): void
    {
        $user = $this->users->find($userId);
        Session::set('pw_stamp', self::passwordStamp($user['password_hash'] ?? null));
    }

    private static function passwordStamp(mixed $hash): string
    {
        return substr(hash('sha256', (string) $hash), 0, 16);
    }

    public function logout(?Request $r = null): void
    {
        $uid = Session::get('uid');
        if (is_int($uid)) {
            $this->audit->record($uid, 'logout', 'users', $uid, null, null, null, $r);
        }
        Session::forget('uid');
        Session::forget('pw_stamp');
        Session::forget('last_activity');
        Session::forget('pending_2fa_user');
        Session::forget('totp_setup_secret');
        Session::forget('totp_reauth');
        Session::forget('totp_attempts');
        Session::regenerate();
    }
}
