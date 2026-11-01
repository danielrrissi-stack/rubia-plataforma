<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class TwoFactorController
{
    public const MAX_ATTEMPTS = 5;
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];

    public function showVerify(Request $r): Response
    {
        if (!is_int(Session::get('pending_2fa_user'))) {
            return Response::redirect('/login');
        }
        return $this->verifyPage(null);
    }

    public function verify(Request $r): Response
    {
        $uid = Session::get('pending_2fa_user');
        if (!is_int($uid)) {
            return Response::redirect('/login');
        }
        $auth = Container::auth();
        if ($auth->isLocked($uid)) {
            return $this->kickOut(AuthService::LOCKED_ERROR);
        }
        $user = Container::users()->find($uid);
        $code = (string) $r->input('codigo', '');
        if ($user !== null && (int) $user['active'] === 1 && (int) $user['totp_enabled'] === 1
            && is_string($user['totp_secret']) && Totp::verify($user['totp_secret'], $code, time())) {
            Session::forget('totp_attempts');
            Container::auth()->completeLogin($uid, $r);
            return Response::redirect('/');
        }

        Container::audit()->record($uid, 'login_failed', 'users', $uid, null, null, ['motivo' => 'totp'], $r);
        $auth->registerFailure($uid);
        if ($auth->isLocked($uid)) {
            return $this->kickOut(AuthService::LOCKED_ERROR);
        }
        $attempts = (int) Session::get('totp_attempts', 0) + 1;
        Session::set('totp_attempts', $attempts);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->kickOut('Muitas tentativas inválidas. Entre novamente.');
        }
        return $this->verifyPage('Código inválido. Tente novamente.');
    }

    private function kickOut(string $message): Response
    {
        Session::forget('pending_2fa_user');
        Session::forget('totp_attempts');
        Session::flash('erro', $message);
        return Response::redirect('/login');
    }

    public function showSetup(Request $r): Response
    {
        $user = $r->attributes['user'];
        if ($this->needsReauth($user)) {
            return $this->reauthPage($user, null);
        }
        $secret = $this->setupSecret((int) $user['id']);
        if ($secret === null) {
            $secret = Totp::generateSecret();
            Session::set('totp_setup_secret', ['uid' => (int) $user['id'], 'secret' => $secret]);
        }
        return $this->setupPage($user, $secret, null);
    }

    public function setup(Request $r): Response
    {
        $user = $r->attributes['user'];
        if ($this->needsReauth($user)) {
            return $this->confirmCurrent($r, $user);
        }
        $secret = $this->setupSecret((int) $user['id']);
        if ($secret === null) {
            return Response::redirect('/2fa/configurar');
        }
        if (!Totp::verify($secret, (string) $r->input('codigo', ''), time())) {
            return $this->setupPage($user, $secret, 'Código inválido. Confira o aplicativo e tente novamente.');
        }
        $id = (int) $user['id'];
        Container::users()->update($id, ['totp_secret' => $secret, 'totp_enabled' => 1]);
        Container::audit()->record($id, 'config', 'users', $id, null, null, ['totp_enabled' => 1], $r);
        Session::forget('totp_setup_secret');
        Session::forget('totp_reauth');
        Session::flash('ok', 'Verificação em duas etapas ativada.');
        return Response::redirect('/');
    }

    /** Com 2FA ja ativo, trocar o aplicativo exige antes o codigo atual (sessao roubada nao troca o segredo). */
    private function needsReauth(array $user): bool
    {
        return (int) $user['totp_enabled'] === 1 && Session::get('totp_reauth') !== (int) $user['id'];
    }

    private function confirmCurrent(Request $r, array $user): Response
    {
        $id = (int) $user['id'];
        $auth = Container::auth();
        if (!$auth->isLocked($id) && is_string($user['totp_secret'])
            && Totp::verify($user['totp_secret'], (string) $r->input('codigo_atual', ''), time())) {
            Session::set('totp_reauth', $id);
            Session::forget('totp_setup_secret');
            return Response::redirect('/2fa/configurar');
        }
        Container::audit()->record($id, 'login_failed', 'users', $id, null, null, ['motivo' => 'totp_reconfigurar'], $r);
        $auth->registerFailure($id);
        if ($auth->isLocked($id)) {
            $auth->logout($r);
            Session::flash('erro', AuthService::LOCKED_ERROR);
            return Response::redirect('/login');
        }
        return $this->reauthPage($user, 'Código atual inválido. Confira o aplicativo e tente novamente.');
    }

    private function reauthPage(array $user, ?string $error): Response
    {
        $staff = in_array($user['role'], self::STAFF, true);
        return Response::html(View::render(
            'Auth/views/totp_reauth.php',
            ['title' => 'Verificação em duas etapas', 'userName' => $user['name'], 'error' => $error],
            $staff ? 'Layout/views/layout.php' : 'Layout/views/layout_portal.php'
        ));
    }

    /** Segredo de configuracao pendente, somente se pertence ao usuario atual. */
    private function setupSecret(int $uid): ?string
    {
        $s = Session::get('totp_setup_secret');
        if (is_array($s) && ($s['uid'] ?? null) === $uid && is_string($s['secret'] ?? null) && $s['secret'] !== '') {
            return $s['secret'];
        }
        return null;
    }

    private function verifyPage(?string $error): Response
    {
        return Response::html(View::render(
            'Auth/views/totp_verify.php',
            ['title' => 'Verificação em duas etapas', 'error' => $error],
            'Layout/views/layout_portal.php'
        ));
    }

    private function setupPage(array $user, string $secret, ?string $error): Response
    {
        $staff = in_array($user['role'], self::STAFF, true);
        return Response::html(View::render(
            'Auth/views/totp_setup.php',
            [
                'title' => 'Verificação em duas etapas',
                'userName' => $user['name'],
                'error' => $error,
                'grouped' => implode(' ', str_split($secret, 4)),
                'uri' => Totp::uri($secret, (string) $user['email']),
                'forced' => $staff && (int) $user['totp_enabled'] !== 1,
            ],
            $staff ? 'Layout/views/layout.php' : 'Layout/views/layout_portal.php'
        ));
    }
}
