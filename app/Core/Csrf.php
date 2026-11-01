<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    /** @throws HttpException 419 quando o token falta ou nao confere. */
    public static function check(Request $request): void
    {
        $given = $request->post['_csrf'] ?? ($request->server['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = Session::get('_csrf');
        if (!is_string($given) || !is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
            throw new HttpException(419, 'Token CSRF invalido');
        }
    }
}
