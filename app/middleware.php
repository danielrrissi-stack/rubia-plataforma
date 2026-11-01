<?php
declare(strict_types=1);

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// 'auth': exige usuario logado (aplica timeout ocioso); anexa o usuario em $request->attributes['user'].
Router::middleware('auth', static function (Request $r, callable $next): Response {
    $user = Container::auth()->user($r);
    if ($user === null) {
        return Response::redirect('/login');
    }
    $r->attributes['user'] = $user;
    return $next($r);
});

// 'twofactor': equipe clinica sem 2FA ativo so acessa a configuracao do 2FA (e o logout). Familia passa.
Router::middleware('twofactor', static function (Request $r, callable $next): Response {
    $user = $r->attributes['user'] ?? null;
    if (is_array($user)
        && in_array($user['role'], ['medico', 'nutricionista', 'secretaria'], true)
        && (int) $user['totp_enabled'] !== 1
        && !str_starts_with($r->path(), '/2fa/configurar')
        && !($r->method() === 'POST' && $r->path() === '/logout')) {
        return Response::redirect('/2fa/configurar');
    }
    return $next($r);
});
