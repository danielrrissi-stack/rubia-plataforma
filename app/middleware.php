<?php
declare(strict_types=1);

use App\Auth\ChildArea;
use App\Core\Container;
use App\Core\HttpException;
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

// 'consent': familia (paciente/responsavel) com termo pendente so acessa /consentimento e o logout. Equipe passa.
Router::middleware('consent', static function (Request $r, callable $next): Response {
    $user = $r->attributes['user'] ?? null;
    if (is_array($user)
        && in_array($user['role'], ['paciente', 'responsavel'], true)
        && $r->path() !== '/consentimento'
        && !($r->method() === 'POST' && $r->path() === '/logout')
        && Container::consents()->pendingFor($user) !== []) {
        return Response::redirect('/consentimento');
    }
    return $next($r);
});

// 'childarea' (ultimo da lista): (1) senha provisoria, qualquer papel: todo GET vai para /senha/trocar e os demais
// metodos sao negados, ate trocar (logout liberado); (2) papel 'crianca' fica preso a propria area de metas:
// GET fora dela redireciona para a area, outros metodos -> 403. Regras em App\Auth\ChildArea.
Router::middleware('childarea', static function (Request $r, callable $next): Response {
    $user = $r->attributes['user'] ?? null;
    if (!is_array($user)) {
        return $next($r);
    }
    $method = $r->method();
    $path = $r->path();
    $deny = static function (string $home, string $motivo) use ($r, $user, $method, $path): Response {
        if ($method === 'GET') {
            return Response::redirect($home);
        }
        Container::audit()->record((int) $user['id'], 'denied', 'users', (int) $user['id'], null, null,
            ['acao' => $motivo, 'rota' => $method . ' ' . $path], $r);
        throw new HttpException(403, 'Acesso negado');
    };
    if ((int) ($user['must_change_password'] ?? 0) === 1 && !ChildArea::allowsWithPendingPassword($method, $path)) {
        return $deny(ChildArea::CHANGE_PASSWORD, 'senha_provisoria');
    }
    if (($user['role'] ?? '') !== 'crianca') {
        return $next($r);
    }
    $patientId = ChildArea::patientIdFor($user);
    if (ChildArea::allows($method, $path, $patientId)) {
        return $next($r);
    }
    return $deny(ChildArea::home($patientId), 'area_crianca');
});
