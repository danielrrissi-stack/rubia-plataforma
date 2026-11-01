<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    private const LAYOUT = 'Layout/views/layout_portal.php';
    private const RESET_TTL_MIN = 60;
    public const INVITE_TTL_MIN = 72 * 60;
    private const MIN_PASSWORD = 10;

    public function showLogin(Request $r): Response
    {
        if (Container::auth()->user($r) !== null) {
            return Response::redirect('/');
        }
        return $this->page('Auth/views/login.php', 'Entrar', ['error' => null, 'email' => '']);
    }

    public function login(Request $r): Response
    {
        $email = (string) $r->input('email', '');
        $result = Container::auth()->attempt($email, (string) $r->input('senha', ''), $r);
        if (!$result->ok) {
            return $this->page('Auth/views/login.php', 'Entrar', ['error' => $result->error, 'email' => $email]);
        }
        return Response::redirect($result->needsTotp ? '/2fa' : '/');
    }

    public function logout(Request $r): Response
    {
        Container::auth()->logout($r);
        return Response::redirect('/login');
    }

    public function showForgot(Request $r): Response
    {
        return $this->page('Auth/views/forgot.php', 'Esqueci minha senha', ['sent' => false]);
    }

    public function forgot(Request $r): Response
    {
        $user = Container::users()->findByEmail((string) $r->input('email', ''));
        if ($user !== null && (int) $user['active'] === 1) {
            $token = Container::tokens()->issue((int) $user['id'], 'reset', self::RESET_TTL_MIN);
            $link = rtrim((string) Config::get('base_url', ''), '/') . '/senha/definir/' . $token;
            $html = '<p>Olá, ' . e($user['name']) . '.</p>'
                . '<p>Para definir uma nova senha, acesse o link abaixo (válido por 1 hora):</p>'
                . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
                . '<p>Se você não pediu esta redefinição, ignore este e-mail.</p>';
            Container::mailer()->queue($user['email'], 'Redefinição de senha — Dra. Rubia', $html);
            Container::audit()->record((int) $user['id'], 'password_reset_requested', 'users', (int) $user['id'], null, null, null, $r);
        }
        return $this->page('Auth/views/forgot.php', 'Esqueci minha senha', ['sent' => true]);
    }

    public function showReset(Request $r): Response
    {
        $this->userIdForToken((string) $r->params['token']);
        return $this->page('Auth/views/reset.php', 'Definir senha', ['error' => null]);
    }

    public function reset(Request $r): Response
    {
        $token = (string) $r->params['token'];
        $this->userIdForToken($token);
        $senha = (string) $r->input('senha', '');
        $conf = (string) $r->input('confirmacao', '');
        $error = null;
        if (mb_strlen($senha) < self::MIN_PASSWORD) {
            $error = 'A senha deve ter pelo menos 10 caracteres.';
        } elseif ($senha !== $conf) {
            $error = 'A senha e a confirmação não conferem.';
        }
        if ($error !== null) {
            return $this->page('Auth/views/reset.php', 'Definir senha', ['error' => $error]);
        }
        $tokens = Container::tokens();
        $userId = $tokens->consume($token, 'reset') ?? $tokens->consume($token, 'invite');
        if ($userId === null) {
            throw new HttpException(404);
        }
        Container::users()->setPassword($userId, $senha);
        Container::audit()->record($userId, 'password_set', 'users', $userId, null, null, null, $r);
        \App\Core\Session::flash('ok', 'Senha definida. Entre com a nova senha.');
        return Response::redirect('/login');
    }

    private function userIdForToken(string $token): int
    {
        $tokens = Container::tokens();
        $id = $tokens->peek($token, 'reset') ?? $tokens->peek($token, 'invite');
        if ($id === null) {
            throw new HttpException(404);
        }
        return $id;
    }

    private function page(string $template, string $title, array $data): Response
    {
        return Response::html(View::render($template, $data + ['title' => $title], self::LAYOUT));
    }
}
