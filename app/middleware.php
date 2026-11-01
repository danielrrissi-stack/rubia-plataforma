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
