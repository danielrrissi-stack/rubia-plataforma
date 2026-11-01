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
        $user = Container::users()->find($uid);
        $code = (string) $r->input('codigo', '');
        if ($user !== null && (int) $user['active'] === 1 && (int) $user['totp_enabled'] === 1
            && is_string($user['totp_secret']) && Totp::verify($user['totp_secret'], $code, time())) {
            Session::forget('totp_attempts');
            Container::auth()->completeLogin($uid, $r);
            return Response::redirect('/');
        }

        Container::audit()->record($uid, 'login_failed', 'users', $uid, null, null, ['motivo' => 'totp'], $r);
        $attempts = (int) Session::get('totp_attempts', 0) + 1;
        Session::set('totp_attempts', $attempts);
        if ($attempts >= self::MAX_ATTEMPTS) {
            Session::forget('pending_2fa_user');
            Session::forget('totp_attempts');
            Session::flash('erro', 'Muitas tentativas inválidas. Entre novamente.');
            return Response::redirect('/login');
        }
        return $this->verifyPage('Código inválido. Tente novamente.');
    }

    public function showSetup(Request $r): Response
    {
        $user = $r->attributes['user'];
        $secret = Session::get('totp_setup_secret');
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            Session::set('totp_setup_secret', $secret);
        }
        return $this->setupPage($user, $secret, null);
    }

    public function setup(Request $r): Response
    {
        $user = $r->attributes['user'];
        $secret = Session::get('totp_setup_secret');
        if (!is_string($secret) || $secret === '') {
            return Response::redirect('/2fa/configurar');
        }
        if (!Totp::verify($secret, (string) $r->input('codigo', ''), time())) {
            return $this->setupPage($user, $secret, 'Código inválido. Confira o aplicativo e tente novamente.');
        }
        $id = (int) $user['id'];
        Container::users()->update($id, ['totp_secret' => $secret, 'totp_enabled' => 1]);
        Container::audit()->record($id, 'config', 'users', $id, null, null, ['totp_enabled' => 1], $r);
        Session::forget('totp_setup_secret');
        Session::flash('ok', 'Verificação em duas etapas ativada.');
        return Response::redirect('/');
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
